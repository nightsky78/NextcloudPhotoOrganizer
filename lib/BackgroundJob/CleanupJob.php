<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\BackgroundJob;

use OCA\PhotoDedup\Db\FileHashMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Periodic job that removes stale hash records for files that no longer exist.
 *
 * This is a safety net: the NodeDeletedEvent listener handles real-time cleanup,
 * but edge cases (e.g. external storage changes, direct DB manipulation) can
 * leave orphaned records.
 */
class CleanupJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private readonly FileHashMapper $mapper,
        private readonly IRootFolder $rootFolder,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);

        // Run every 7 days
        $this->setInterval(7 * 24 * 60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void
    {
        $this->logger->info('PhotoDedup cleanup job started');

        $this->userManager->callForSeenUsers(function (\OCP\IUser $user): void {
            $this->cleanupUser($user->getUID());
        });

        $this->logger->info('PhotoDedup cleanup job finished');
    }

    private function cleanupUser(string $userId): void
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('Cleanup: cannot get user folder', [
                'userId' => $userId,
                'exception' => $e,
            ]);
            return;
        }

        // Check each indexed file still exists
        $hashes = $this->mapper->findDuplicateHashes($userId, 10000, 0);

        foreach ($hashes as $hashGroup) {
            $files = $this->mapper->findByContentHash($userId, $hashGroup['content_hash']);

            foreach ($files as $fileHash) {
                $nodes = $userFolder->getById($fileHash->getFileId());
                if (empty($nodes)) {
                    // File no longer exists — remove index entry
                    $this->mapper->deleteByFileId($userId, $fileHash->getFileId());
                    $this->logger->debug('Cleanup: removed stale record', [
                        'userId' => $userId,
                        'fileId' => $fileHash->getFileId(),
                        'path' => $fileHash->getFilePath(),
                    ]);
                }
            }
        }
    }
}
