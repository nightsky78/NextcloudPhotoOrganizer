<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Service;

use OCA\PhotoDedup\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Scans a user's file tree for image files and computes content hashes.
 *
 * The scanner operates in two phases:
 *   1. Discover — recursively walk the user's root folder collecting image files.
 *   2. Hash    — for each discovered file, compute SHA-256 if not already indexed
 *                or if the file has changed since last scan.
 *
 * Progress is stored in user config so the frontend can poll status.
 */
class ScannerService
{
    /** Maximum bytes to read per chunk when streaming hash computation. */
    private const HASH_CHUNK_SIZE = 8 * 1024 * 1024; // 8 MiB

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FileHashService $fileHashService,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Full scan of the user's file tree.
     *
     * @param string $userId         The user whose files to scan.
     * @param bool   $forceRehash    Re-hash even if mtime/size unchanged.
     * @return array{total: int, hashed: int, skipped: int, errors: int}
     */
    public function scanUser(string $userId, bool $forceRehash = false): array
    {
        $this->setProgress($userId, 'scanning', 0, 0);

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (NotFoundException $e) {
            $this->logger->warning('User folder not found, aborting scan', [
                'userId' => $userId,
                'exception' => $e,
            ]);
            $this->setProgress($userId, 'error', 0, 0);
            return ['total' => 0, 'hashed' => 0, 'skipped' => 0, 'errors' => 1];
        }

        // Phase 1: Discover image files
        $imageFiles = [];
        $this->collectImageFiles($userFolder, $imageFiles);
        $total = count($imageFiles);

        $this->setProgress($userId, 'scanning', $total, 0);

        // Phase 2: Hash each file
        $hashed = 0;
        $skipped = 0;
        $errors = 0;
        $processed = 0;

        foreach ($imageFiles as $file) {
            try {
                $wasHashed = $this->processFile($userId, $file, $forceRehash);
                if ($wasHashed) {
                    $hashed++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Failed to process file during scan', [
                    'userId' => $userId,
                    'filePath' => $file->getPath(),
                    'exception' => $e,
                ]);
            }

            $processed++;
            // Update progress every 50 files to avoid excessive DB writes
            if ($processed % 50 === 0 || $processed === $total) {
                $this->setProgress($userId, 'scanning', $total, $processed);
            }
        }

        // Phase 3: Cleanup stale records (files that no longer exist)
        $this->cleanupStaleRecords($userId, $userFolder);

        $this->setProgress($userId, 'completed', $total, $total);

        $this->logger->info('Scan completed', [
            'userId' => $userId,
            'total' => $total,
            'hashed' => $hashed,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return compact('total', 'hashed', 'skipped', 'errors');
    }

    /**
     * Process a single file: compute hash if needed and update the index.
     *
     * @return bool True if the file was hashed, false if skipped (unchanged).
     */
    public function processFile(string $userId, File $file, bool $forceRehash = false): bool
    {
        $fileId = $file->getId();
        $mtime = $file->getMTime();
        $size = $file->getSize();

        if ($fileId === null || $size === 0) {
            return false;
        }

        // Skip if unchanged since last scan
        if (!$forceRehash && !$this->fileHashService->isFileChanged($userId, $fileId, $mtime, $size)) {
            return false;
        }

        $hash = $this->computeHash($file);
        if ($hash === null) {
            return false;
        }

        // Get path relative to user folder root
        $filePath = $this->getUserRelativePath($userId, $file);

        $this->fileHashService->upsert(
            userId: $userId,
            fileId: $fileId,
            filePath: $filePath,
            fileSize: $size,
            contentHash: $hash,
            mimeType: $file->getMimeType(),
            fileMtime: $mtime,
        );

        return true;
    }

    /**
     * Get scan progress for a user.
     *
     * @return array{status: string, total: int, processed: int, updated_at: string}
     */
    public function getProgress(string $userId): array
    {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, 'scan_progress', '');
        if ($raw === '') {
            return [
                'status' => 'idle',
                'total' => 0,
                'processed' => 0,
                'updated_at' => '',
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [
                'status' => 'idle',
                'total' => 0,
                'processed' => 0,
                'updated_at' => '',
            ];
        }

        return [
            'status' => (string) ($data['status'] ?? 'idle'),
            'total' => (int) ($data['total'] ?? 0),
            'processed' => (int) ($data['processed'] ?? 0),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        ];
    }

    // ── Internal helpers ────────────────────────────────────────────

    /**
     * Recursively collect all image files from a folder.
     *
     * @param File[] &$result Accumulator passed by reference.
     */
    private function collectImageFiles(Folder $folder, array &$result): void
    {
        try {
            $nodes = $folder->getDirectoryListing();
        } catch (\Throwable $e) {
            $this->logger->warning('Cannot list directory, skipping', [
                'path' => $folder->getPath(),
                'exception' => $e,
            ]);
            return;
        }

        foreach ($nodes as $node) {
            if ($node instanceof Folder) {
                // Skip known non-image directories for performance
                $name = $node->getName();
                if ($name === '.thumbnails' || $name === '.versions' || $name === '.trash') {
                    continue;
                }
                $this->collectImageFiles($node, $result);
            } elseif ($node instanceof File) {
                if ($this->isImageFile($node)) {
                    $result[] = $node;
                }
            }
        }
    }

    /**
     * Determine whether a node is a supported image file.
     */
    private function isImageFile(File $file): bool
    {
        $mime = $file->getMimeType();
        return in_array($mime, Application::SUPPORTED_MIME_TYPES, true);
    }

    /**
     * Compute SHA-256 hash of file content using streaming reads.
     *
     * @return string|null Hex-encoded hash, or null on failure.
     */
    private function computeHash(File $file): ?string
    {
        try {
            $handle = $file->fopen('r');
        } catch (\Throwable $e) {
            $this->logger->warning('Cannot open file for hashing', [
                'path' => $file->getPath(),
                'exception' => $e,
            ]);
            return null;
        }

        if (!is_resource($handle)) {
            return null;
        }

        try {
            $ctx = hash_init('sha256');
            while (!feof($handle)) {
                $chunk = fread($handle, self::HASH_CHUNK_SIZE);
                if ($chunk === false) {
                    return null;
                }
                hash_update($ctx, $chunk);
            }
            return hash_final($ctx);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Get a file's path relative to the user's root folder.
     */
    private function getUserRelativePath(string $userId, File $file): string
    {
        $prefix = "/{$userId}/files/";
        $fullPath = $file->getPath();

        if (str_starts_with($fullPath, $prefix)) {
            return substr($fullPath, strlen($prefix));
        }

        // Fallback: return the full internal path
        return $file->getInternalPath();
    }

    /**
     * Remove index records for files that no longer exist on storage.
     */
    private function cleanupStaleRecords(string $userId, Folder $userFolder): void
    {
        $batchSize = 1000;
        $lastSeenId = 0;
        $removed = 0;

        while (true) {
            $records = $this->fileHashService->listForUserAfterId($userId, $lastSeenId, $batchSize);
            if ($records === []) {
                break;
            }

            foreach ($records as $record) {
                $lastSeenId = max($lastSeenId, $record->getId() ?? 0);
                $fileId = $record->getFileId();

                try {
                    $nodes = $userFolder->getById($fileId);
                } catch (\Throwable $e) {
                    $nodes = [];
                }

                if ($nodes === []) {
                    $this->fileHashService->deleteByFileId($userId, $fileId);
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->logger->info('Removed stale hash index records after scan', [
                'userId' => $userId,
                'removed' => $removed,
            ]);
        }
    }

    private function setProgress(string $userId, string $status, int $total, int $processed): void
    {
        $data = json_encode([
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'updated_at' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $this->config->setUserValue($userId, Application::APP_ID, 'scan_progress', $data);
    }
}
