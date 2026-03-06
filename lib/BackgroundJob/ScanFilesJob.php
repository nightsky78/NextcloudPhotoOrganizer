<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\BackgroundJob;

use OCA\PhotoDedup\Service\ScannerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Periodic background job that scans user files for duplicate images.
 *
 * Runs once every 24 hours per registered interval. Each execution scans
 * one user at a time (round-robin via app config) to avoid long-running jobs.
 */
class ScanFilesJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private readonly ScannerService $scannerService,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);

        // Run every 24 hours
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run(mixed $argument): void
    {
        $this->logger->info('PhotoDedup background scan started');

        $scannedUsers = 0;

        $this->userManager->callForSeenUsers(function (\OCP\IUser $user) use (&$scannedUsers): void {
            $userId = $user->getUID();

            try {
                $result = $this->scannerService->scanUser($userId);
                $this->logger->info('Background scan completed for user', [
                    'userId' => $userId,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Background scan failed for user', [
                    'userId' => $userId,
                    'exception' => $e,
                ]);
            }

            $scannedUsers++;
        });

        $this->logger->info('PhotoDedup background scan finished', [
            'usersScanned' => $scannedUsers,
        ]);
    }
}
