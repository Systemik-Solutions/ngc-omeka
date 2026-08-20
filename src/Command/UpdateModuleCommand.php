<?php

namespace App\Command;

use App\Distribution\Auditor;
use App\Distribution\CodeUpdater;
use App\Distribution\DbUpdater;
use App\Distribution\Inspector;
use App\Distribution\Manifest;
use App\Omeka;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Updates modules on their own, code and database.
 *
 * With no arguments this covers every module in distribution.json - and only those, so a module
 * added to the installation by hand is never touched. Naming modules narrows it further; a name
 * that is not in the manifest is an error rather than a silent no-op.
 *
 * Like update:core, the download and the database work happen in that order in one process, and the
 * audit covers both sides so that a run interrupted between them can be finished by re-running.
 */
class UpdateModuleCommand extends AbstractUpdateCommand
{
    protected function configure(): void
    {
        $this->setName('update:module');
        $this->setDescription('Updates the distribution modules only, and applies their pending database updates.');
        $this->addArgument('modules', InputArgument::IS_ARRAY, 'The IDs of the modules to update, separated by spaces. Defaults to every module in distribution.json.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-download and re-extract the modules even when they are already at the version in distribution.json.');
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

        // Audit without bootstrapping Omeka: the module classes are about to be replaced on disk.
        $output->writeln('Checking for module updates...');
        try {
            $manifest = new Manifest($rootDir);
            $ids = $manifest->resolveModuleIds($input->getArgument('modules'));
            $auditor = new Auditor($manifest, $inspector);

            // The "Mapping" to "MappingExtensions" rename cannot be automated, so stop before doing
            // anything if this run would touch MappingExtensions on an installation still carrying
            // the legacy module. Other modules are unaffected and stay updatable.
            if (in_array('MappingExtensions', $ids, true) && $auditor->hasLegacyMapping()) {
                $output->writeln('<error>Module "MappingExtensions" update from version 1.0.0 requires a manual update before running the distribution update command. Please refer to the README.md and https://github.com/Systemik-Solutions/OmekaS-MappingExtensions#upgrading-from-100-to-101 for more details.</error>');
                return Command::FAILURE;
            }

            $plan = $auditor->auditModules($ids, $force);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($plan)) {
            $output->writeln('<info>No updates available. The selected modules are up to date.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('Module updates available:');
        foreach ($plan as $id => $entry) {
            $parts = [];
            if ($entry['code'] && $entry['code']['forced']) {
                $parts[] = 'code: forced re-download of ' . $entry['code']['to'];
            } elseif ($entry['code']) {
                $parts[] = 'code: ' . $this->formatVersions($entry['code']['from'], $entry['code']['to']);
            }
            if ($entry['db']) {
                $parts[] = 'database: ' . $this->formatVersions($entry['db']['from'], $entry['db']['to']);
            }
            $output->writeln($id . ': ' . implode(', ', $parts));
        }

        $output->writeln('<error>CAUTION: Updating may disrupt your current installation. Before proceeding, please ensure you have a complete backup of both the Omeka S codebase (especially the "public" directory) and the database.</error>');

        if (!$this->confirm($input, $output, $autoConfirm)) {
            return Command::SUCCESS;
        }

        $hasErrors = false;

        // Download everything that needs new code. A module whose download fails has been rolled back
        // to the code it was already running, so it is dropped from the database phase below: running
        // an upgrade against the old code would record a version the module is not at.
        $codeUpdater = new CodeUpdater($rootDir, fn ($message) => $output->writeln($message));
        Omeka::lockBootstrap();
        try {
            foreach ($plan as $id => $entry) {
                if (!$entry['code']) {
                    continue;
                }
                try {
                    $codeUpdater->updateModule($id, $manifest->getModule($id));
                } catch (\RuntimeException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    unset($plan[$id]);
                    $hasErrors = true;
                }
            }
        } finally {
            Omeka::unlockBootstrap();
        }

        if (!empty($plan)) {
            // First bootstrap of the process, now that the new module code is on disk. Installing and
            // upgrading modules runs Omeka code that expects an identity, so unlike the core
            // migrations this step does need to authenticate.
            if (!$this->authenticateWithOmeka($output, $credentials)) {
                return Command::FAILURE;
            }

            $dbUpdater = new DbUpdater();
            // Re-audit against the running module manager, which is authoritative once the new code
            // is loaded, and restrict it to the modules this run is responsible for.
            $pending = $dbUpdater->auditModules(array_keys($plan));
            foreach ($pending as $id => $versions) {
                $output->writeln("Applying update for module {$id}...");
                try {
                    $dbUpdater->updateModule($id);
                } catch (\RuntimeException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    $hasErrors = true;
                }
            }

            // A module Omeka refuses to load never reaches a state the audit above can act on, so
            // the database work this command reported would be skipped in silence. Report it.
            foreach (array_keys($plan) as $id) {
                if (isset($pending[$id])) {
                    continue;
                }
                $hasErrors = $this->reportIfBlocked($output, $dbUpdater, $id) || $hasErrors;
            }
        }

        if ($hasErrors) {
            $output->writeln('<comment>Some module updates failed. Please check the messages above, and try to manually update the affected modules via the UI.</comment>');
            return Command::FAILURE;
        }

        $output->writeln('<info>The modules have been updated successfully.</info>');

        return Command::SUCCESS;
    }

    /**
     * Report a module the running Omeka refuses to load.
     *
     * The pre-download audit compares version numbers only, so it will happily report pending
     * database work for a module that Omeka then declines to load at all - most often because the
     * new version of the module needs a newer core than the one installed. Left unreported, the
     * command would apply nothing and still claim success.
     *
     * @return bool True when the module is blocked, i.e. the run has failed.
     */
    private function reportIfBlocked(OutputInterface $output, DbUpdater $dbUpdater, string $id): bool
    {
        $status = $dbUpdater->getModuleStatus($id);

        if ($status === null) {
            $output->writeln("<error>Module {$id} could not be found by Omeka S after the update.</error>");
            return true;
        }

        if (!$status['blocked']) {
            return false;
        }

        $output->writeln(sprintf(
            '<error>Module %s is on disk at version %s but Omeka S reports it as "%s", so its database update could not be applied. It is still recorded at version %s.</error>',
            $id,
            $status['ini'] ?? 'unknown',
            $status['state'],
            $status['db'] ?? 'not installed'
        ));

        if ($status['state'] === \Omeka\Module\Manager::STATE_INVALID_OMEKA_VERSION) {
            $output->writeln(sprintf(
                '<comment>Module %s requires Omeka S %s. Run "php console update:core" first, then this command again.</comment>',
                $id,
                $status['constraint'] ?? 'a newer version'
            ));
        }

        return true;
    }
}
