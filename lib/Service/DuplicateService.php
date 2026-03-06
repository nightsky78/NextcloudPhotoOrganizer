<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Service;

use OCA\PhotoDedup\Db\FileHash;
use OCA\PhotoDedup\Db\FileHashMapper;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * High-level operations for duplicate discovery and resolution.
 */
class DuplicateService
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';

    public function __construct(
        private readonly FileHashMapper $mapper,
        private readonly FileHashService $fileHashService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Duplicate group listing ─────────────────────────────────────

    /**
     * List duplicate groups (paginated).
     *
     * @return array{groups: array<int, array>, total: int}
     */
    public function getDuplicateGroups(string $userId, int $limit = 50, int $offset = 0, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);
        $total = $this->mapper->countDuplicateHashes($userId, $scope);
        $hashes = $this->mapper->findDuplicateHashes($userId, $limit, $offset, $scope);

        $groups = [];
        foreach ($hashes as $row) {
            $files = $this->mapper->findByContentHash($userId, $row['content_hash'], $scope);
            $groups[] = [
                'contentHash' => $row['content_hash'],
                'count' => $row['count'],
                'totalSize' => $row['total_size'],
                'files' => array_map(static fn(FileHash $fh): array => $fh->jsonSerialize(), $files),
            ];
        }

        return [
            'groups' => $groups,
            'total' => $total,
        ];
    }

    /**
     * Get details for a single duplicate group.
     *
     * @return array{contentHash: string, count: int, files: array}|null
     */
    public function getDuplicateGroup(string $userId, string $contentHash, string $scope = self::SCOPE_ALL): ?array
    {
        $scope = $this->normalizeScope($scope);

        // Validate hash format (SHA-256 hex)
        if (!preg_match('/\A[0-9a-f]{64}\z/', $contentHash)) {
            return null;
        }

        $files = $this->mapper->findByContentHash($userId, $contentHash, $scope);
        if (count($files) < 2) {
            return null; // Not a duplicate group
        }

        $totalSize = 0;
        $serialized = [];
        foreach ($files as $file) {
            $totalSize += $file->getFileSize();
            $serialized[] = $file->jsonSerialize();
        }

        return [
            'contentHash' => $contentHash,
            'count' => count($files),
            'totalSize' => $totalSize,
            'files' => $serialized,
        ];
    }

    // ── Deletion ────────────────────────────────────────────────────

    /**
     * Delete a single file by its Nextcloud file ID.
     *
     * Safety: refuses to delete the last remaining copy in a duplicate group.
     *
     * @return array{success: bool, message: string}
     */
    public function deleteFile(string $userId, int $fileId): array
    {
        // Look up the hash record
        $hashRecord = $this->fileHashService->getByFileId($userId, $fileId);
        if ($hashRecord === null) {
            return ['success' => false, 'message' => 'File not found in duplicate index.'];
        }

        // Safety check: ensure at least one copy remains after deletion
        $siblings = $this->fileHashService->getByContentHash($userId, $hashRecord->getContentHash());
        if (count($siblings) <= 1) {
            return ['success' => false, 'message' => 'Cannot delete the last remaining copy.'];
        }

        // Attempt deletion via Nextcloud file API
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);

            if (empty($nodes)) {
                // File already gone from storage — clean up index
                $this->fileHashService->deleteByFileId($userId, $fileId);
                return ['success' => true, 'message' => 'File was already removed; index cleaned up.'];
            }

            $node = null;
            foreach ($nodes as $candidateNode) {
                if ($candidateNode->isDeletable()) {
                    $node = $candidateNode;
                    break;
                }
            }

            if ($node === null) {
                return ['success' => false, 'message' => 'File is not deletable (permissions or mount restrictions).'];
            }

            $path = $node->getPath();
            $node->delete(); // Goes to trash if trashbin app is enabled

            // Remove from index
            $this->fileHashService->deleteByFileId($userId, $fileId);

            $this->logger->info('Duplicate file deleted', [
                'userId' => $userId,
                'fileId' => $fileId,
                'path' => $path,
            ]);

            return ['success' => true, 'message' => 'File deleted successfully.'];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete duplicate file', [
                'userId' => $userId,
                'fileId' => $fileId,
                'exception' => $e,
            ]);
            return ['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()];
        }
    }

    /**
     * Delete multiple files by their IDs.
     *
     * Safety: refuses to delete ALL copies of any hash group within the request.
     *
     * @param int[] $fileIds
     * @return array{deleted: int, failed: int, results: array}
     */
    public function bulkDelete(string $userId, array $fileIds): array
    {
        // Pre-validate: group requested deletions by content hash and ensure
        // at least one copy remains per group.
        $hashGroups = $this->groupFileIdsByHash($userId, $fileIds);

        $results = [];
        $deleted = 0;
        $failed = 0;

        foreach ($fileIds as $fileId) {
            $fileId = (int) $fileId;

            // Safety: check if this deletion would remove the last copy
            if (!$this->isSafeToDelete($userId, $fileId, $hashGroups)) {
                $results[] = [
                    'fileId' => $fileId,
                    'success' => false,
                    'message' => 'Skipped: would remove the last copy.',
                ];
                $failed++;
                continue;
            }

            $result = $this->deleteFile($userId, $fileId);
            $results[] = array_merge(['fileId' => $fileId], $result);

            if ($result['success']) {
                $deleted++;
                // Update tracking so subsequent checks account for this deletion
                $this->decrementHashGroupCount($userId, $fileId, $hashGroups);
            } else {
                $failed++;
            }
        }

        return compact('deleted', 'failed', 'results');
    }

    // ── Statistics ──────────────────────────────────────────────────

    /**
     * @return array{indexed_files: int, duplicate_groups: int, duplicate_files: int, wasted_bytes: int}
     */
    public function getStats(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);

        return [
            'indexed_files' => $this->mapper->countForUser($userId, $scope),
            'duplicate_groups' => $this->mapper->countDuplicateHashes($userId, $scope),
            'duplicate_files' => $this->mapper->countDuplicateFiles($userId, $scope),
            'wasted_bytes' => $this->mapper->totalWastedBytes($userId, $scope),
        ];
    }

    private function normalizeScope(string $scope): string
    {
        return $scope === self::SCOPE_PHOTOS ? self::SCOPE_PHOTOS : self::SCOPE_ALL;
    }

    // ── Internal helpers ────────────────────────────────────────────

    /**
     * Build a map of content_hash → remaining count for safety checks.
     *
     * @param int[] $fileIds
     * @return array<string, array{total: int}>
     */
    private function groupFileIdsByHash(string $userId, array $fileIds): array
    {
        $groups = [];

        foreach ($fileIds as $fileId) {
            $record = $this->fileHashService->getByFileId($userId, (int) $fileId);
            if ($record === null) {
                continue;
            }

            $hash = $record->getContentHash();
            if (!isset($groups[$hash])) {
                $siblings = $this->fileHashService->getByContentHash($userId, $hash);
                $groups[$hash] = [
                    'total' => count($siblings),
                ];
            }
        }

        return $groups;
    }

    /**
     * Check if deleting this file would leave at least one copy.
     *
    * @param array<string, array{total: int}> $hashGroups
     */
    private function isSafeToDelete(string $userId, int $fileId, array $hashGroups): bool
    {
        $record = $this->fileHashService->getByFileId($userId, $fileId);
        if ($record === null) {
            return false;
        }

        $hash = $record->getContentHash();
        if (!isset($hashGroups[$hash])) {
            return false;
        }

        // Must keep at least one copy after deleting the current file.
        return $hashGroups[$hash]['total'] > 1;
    }

    /**
     * After a successful deletion, decrement the tracking counter.
     *
    * @param array<string, array{total: int}> &$hashGroups
     */
    private function decrementHashGroupCount(string $userId, int $fileId, array &$hashGroups): void
    {
        $record = $this->fileHashService->getByFileId($userId, $fileId);
        if ($record === null) {
            return;
        }

        $hash = $record->getContentHash();
        if (isset($hashGroups[$hash])) {
            $hashGroups[$hash]['total']--;
        }
    }
}
