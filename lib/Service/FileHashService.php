<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Service;

use DateTime;
use OCA\PhotoDedup\Db\FileHash;
use OCA\PhotoDedup\Db\FileHashMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * CRUD operations on the file-hash index.
 */
class FileHashService
{
    public function __construct(
        private readonly FileHashMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Read ────────────────────────────────────────────────────────

    public function getByFileId(string $userId, int $fileId): ?FileHash
    {
        try {
            return $this->mapper->findByFileId($userId, $fileId);
        } catch (DoesNotExistException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('FileHashService::getByFileId failed', [
                'userId' => $userId,
                'fileId' => $fileId,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * @return FileHash[]
     */
    public function getByContentHash(string $userId, string $contentHash): array
    {
        return $this->mapper->findByContentHash($userId, $contentHash);
    }

    /**
     * List indexed hash records for a user (paginated).
     *
     * @return FileHash[]
     */
    public function listForUser(string $userId, int $limit = 1000, int $offset = 0): array
    {
        return $this->mapper->findForUser($userId, $limit, $offset);
    }

    /**
     * List indexed hash records for a user using keyset pagination.
     *
     * @return FileHash[]
     */
    public function listForUserAfterId(string $userId, int $afterId = 0, int $limit = 1000): array
    {
        return $this->mapper->findForUserAfterId($userId, $afterId, $limit);
    }

    // ── Write ───────────────────────────────────────────────────────

    /**
     * Insert or update a file-hash record.
     *
     * Uses an upsert strategy: if a record for the same (userId, fileId) exists
     * it is updated, otherwise a new one is created.
     */
    public function upsert(
        string $userId,
        int $fileId,
        string $filePath,
        int $fileSize,
        string $contentHash,
        string $mimeType,
        int $fileMtime,
    ): FileHash {
        $existing = $this->getByFileId($userId, $fileId);

        if ($existing !== null) {
            $existing->setFilePath($filePath);
            $existing->setFileSize($fileSize);
            $existing->setContentHash($contentHash);
            $existing->setMimeType($mimeType);
            $existing->setFileMtime($fileMtime);
            $existing->setScannedAt(new DateTime());

            return $this->mapper->update($existing);
        }

        $entity = new FileHash();
        $entity->setUserId($userId);
        $entity->setFileId($fileId);
        $entity->setFilePath($filePath);
        $entity->setFileSize($fileSize);
        $entity->setContentHash($contentHash);
        $entity->setMimeType($mimeType);
        $entity->setFileMtime($fileMtime);
        $entity->setScannedAt(new DateTime());

        try {
            return $this->mapper->insert($entity);
        } catch (\OC\DB\Exceptions\DbalException $e) {
            // Race condition: another process inserted between our read and write.
            // Retry as an update.
            if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $retryExisting = $this->getByFileId($userId, $fileId);
                if ($retryExisting !== null) {
                    $retryExisting->setFilePath($filePath);
                    $retryExisting->setFileSize($fileSize);
                    $retryExisting->setContentHash($contentHash);
                    $retryExisting->setMimeType($mimeType);
                    $retryExisting->setFileMtime($fileMtime);
                    $retryExisting->setScannedAt(new DateTime());

                    return $this->mapper->update($retryExisting);
                }
            }
            throw $e;
        }
    }

    // ── Delete ──────────────────────────────────────────────────────

    public function deleteByFileId(string $userId, int $fileId): void
    {
        $this->mapper->deleteByFileId($userId, $fileId);
    }

    public function deleteAllForUser(string $userId): int
    {
        return $this->mapper->deleteAllForUser($userId);
    }

    // ── Query helpers ───────────────────────────────────────────────

    /**
     * Check whether the file has changed since it was last indexed.
     */
    public function isFileChanged(string $userId, int $fileId, int $currentMtime, int $currentSize): bool
    {
        $existing = $this->getByFileId($userId, $fileId);
        if ($existing === null) {
            return true; // Not indexed yet → treat as changed.
        }

        return $existing->getFileMtime() !== $currentMtime
            || $existing->getFileSize() !== $currentSize;
    }
}
