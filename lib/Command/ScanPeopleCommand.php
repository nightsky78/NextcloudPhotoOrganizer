<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Command;

use OCA\PhotoDedup\Service\PeopleLocationService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: occ photodedup:scan-people [--force] [--all] [userId]
 *
 * Scans image files for face signatures using the ML worker and stores
 * the results in the database. Designed for background execution via
 * cron job or systemd service.
 */
class ScanPeopleCommand extends Command
{
    public function __construct(
        private readonly PeopleLocationService $peopleLocationService,
        private readonly IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('photodedup:scan-people')
            ->setDescription('Scan image files for face signatures (people detection)')
            ->addArgument(
                'userId',
                InputArgument::OPTIONAL,
                'User ID to scan (omit with --all to scan every user)',
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Scan all users',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Re-scan all files even if unchanged since last scan',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('userId');
        $scanAll = $input->getOption('all');
        $force = $input->getOption('force');

        if ($userId === null && !$scanAll) {
            $output->writeln('<error>Provide a user ID or use --all to scan every user.</error>');
            return Command::FAILURE;
        }

        if ($userId !== null && $scanAll) {
            $output->writeln('<error>Cannot specify both a user ID and --all.</error>');
            return Command::FAILURE;
        }

        if ($scanAll) {
            return $this->scanAllUsers($output, $force);
        }

        // Validate user exists
        if (!is_string($userId) || !$this->userManager->userExists($userId)) {
            $output->writeln("<error>User '{$userId}' does not exist.</error>");
            return Command::FAILURE;
        }

        return $this->scanSingleUser($output, $userId, $force);
    }

    private function scanSingleUser(OutputInterface $output, string $userId, bool $force): int
    {
        $output->writeln("Scanning people for user <info>{$userId}</info>...");

        $result = $this->peopleLocationService->scanPeopleData($userId, $force);

        $output->writeln(sprintf(
            '  Total: %d | Scanned: %d | Skipped: %d | With face: %d | Errors: %d',
            $result['total'],
            $result['scanned'],
            $result['skipped'],
            $result['with_face'],
            $result['errors'],
        ));

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function scanAllUsers(OutputInterface $output, bool $force): int
    {
        $hasErrors = false;

        $this->userManager->callForSeenUsers(function (\OCP\IUser $user) use ($output, $force, &$hasErrors): void {
            $userId = $user->getUID();
            $result = $this->scanSingleUser($output, $userId, $force);
            if ($result !== Command::SUCCESS) {
                $hasErrors = true;
            }
        });

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
