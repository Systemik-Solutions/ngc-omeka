<?php

namespace App\Command;

use App\Distribution\Auditor;
use App\Distribution\CodeUpdater;
use App\Distribution\DbUpdater;
use App\Distribution\Inspector;
use App\Distribution\Manifest;
use App\Omeka;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Updates the Omeka S core on its own, code and database.
 *
 * Self-contained by design: it downloads the core and then runs the migrations in the same process,
 * in that order, which is the only ordering PHP allows. See App\Command\UpdateCommand for why.
 *
 * The audit covers both sides independently, so this also finishes a previous run that downloaded
 * the core but never migrated: the code reads as up to date while the database is still behind.
 */
class UpdateCoreCommand extends AbstractUpdateCommand
{
    protected function configure(): void
    {
        $this->setName('update:core');
        $this->setDescription('Updates the Omeka S core only, and applies its pending database migrations.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-download and re-extract the core even when it is already at the version in distribution.json.');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatic yes to prompts; assume "yes" as answer to all prompts and run non-interactively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $autoConfirm = (bool) $input->getOption('yes');
        $rootDir = $this->getRootDir();

        $credentials = $this->readCredentials($output);
        if ($credentials === null) {
            return Command::FAILURE;
        }

        $inspector = new Inspector($rootDir);
        if (!$this->verifyCredentials($output, $inspector, $credentials)) {
            return Command::FAILURE;
        }

        // Audit without bootstrapping Omeka: the core classes are about to be replaced on disk.
        $output->writeln('Checking for core updates...');
        try {
            $manifest = new Manifest($rootDir);
            $plan = (new Auditor($manifest, $inspector))->auditCore($force);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($plan['code'] && $plan['code']['forced']) {
            $output->writeln('Core code: forced re-download of ' . $plan['code']['to'] . '.');
        } elseif ($plan['code']) {
            $output->writeln('Core code update available: ' . $this->formatVersions($plan['code']['from'], $plan['code']['to']));
        } else {
            $output->writeln('Core code is up to date.');
        }
        if ($plan['db']) {
            $output->writeln('Core database update available: ' . $this->formatVersions($plan['db']['from'], $plan['db']['to']));
        } else {
            $output->writeln('Core database is up to date.');
        }

        if (!$plan['code'] && !$plan['db']) {
            $output->writeln('<info>No updates available. The core is up to date.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>CAUTION: Updating may disrupt your current installation. Before proceeding, please ensure you have a complete backup of both the Omeka S codebase (especially the "public" directory) and the database.</error>');
        if ($plan['code']) {
            $output->writeln('<error>The core code is replaced in place and cannot be rolled back automatically if the download or extraction fails.</error>');
        }

        if (!$this->confirm($input, $output, $autoConfirm)) {
            return Command::SUCCESS;
        }

        if ($plan['code']) {
            // Guard the ordering: bootstrapping Omeka here would load the very code being replaced.
            Omeka::lockBootstrap();
            try {
                (new CodeUpdater($rootDir, fn ($message) => $output->writeln($message)))
                    ->updateCore($manifest->getCore());
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                // The core on disk is now in an unknown state, so migrating against it would be
                // worse than stopping here.
                $output->writeln('<error>The core code update failed, so the database was left untouched.</error>');
                return Command::FAILURE;
            } finally {
                Omeka::unlockBootstrap();
            }
        }

        // First bootstrap of the process, now that the new core is on disk.
        if (!Omeka::authenticate($credentials['email'], $credentials['password'])) {
            $output->writeln('<error>Could not authenticate with Omeka S using the credentials in config.json.</error>');
            return Command::FAILURE;
        }

        $dbUpdater = new DbUpdater();
        if ($dbUpdater->auditCore() !== null) {
            $output->writeln('Applying core updates...');
            try {
                $dbUpdater->updateCore();
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('<info>Core update applied successfully.</info>');
        }

        $output->writeln('<info>The core has been updated successfully.</info>');

        return Command::SUCCESS;
    }
}
