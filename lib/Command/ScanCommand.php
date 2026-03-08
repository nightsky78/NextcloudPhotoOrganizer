<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Command;

use OCA\PhotoDedup\Service\ScannerService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: occ photodedup:scan [--force] [--all] [userId]
 */
class ScanCommand extends Command
{
    public function __construct(
        private readonly ScannerService $scannerService,
        private readonly IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('photodedup:scan')
            ->setAliases(['photodedup:scan-duplicates'])
            ->setDescription('Scan files for duplicate detection')
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
                'Re-hash all files even if unchanged since last scan',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('userId');
        $scanAll = $input->getOption('all');
        $forceRehash = $input->getOption('force');

        if ($userId === null && !$scanAll) {
            $output->writeln('<error>Provide a user ID or use --all to scan every user.</error>');
            return Command::FAILURE;
        }

        if ($userId !== null && $scanAll) {
            $output->writeln('<error>Cannot specify both a user ID and --all.</error>');
            return Command::FAILURE;
        }

        if ($scanAll) {
            return $this->scanAllUsers($output, $forceRehash);
        }

        // Validate user exists
        if (!is_string($userId) || !$this->userManager->userExists($userId)) {
            $output->writeln("<error>User '{$userId}' does not exist.</error>");
            return Command::FAILURE;
        }

        return $this->scanSingleUser($output, $userId, $forceRehash);
    }

    private function scanSingleUser(OutputInterface $output, string $userId, bool $force): int
    {
        $output->writeln("Scanning files for user <info>{$userId}</info>...");

        $result = $this->scannerService->scanUser($userId, $force);

        $output->writeln(sprintf(
            '  Total: %d | Hashed: %d | Skipped: %d | Errors: %d',
            $result['total'],
            $result['hashed'],
            $result['skipped'],
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
