<?php

namespace App\Command;

use App\Distribution\DbUpdater;
use App\Omeka;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Applies pending database work after a code update.
 *
 * Scope is deliberately everything the module manager knows about, not just the modules listed in
 * distribution.json, so that a module added to the installation by hand is still installed or
 * upgraded here. Use update:module to restrict the work to the distribution's own modules, or to
 * named ones.
 *
 * This is the first thing in a combined update run that bootstraps Omeka S, and it must stay that
 * way: see App\Command\UpdateCommand.
 */
class UpdateDBCommand extends AbstractUpdateCommand
{
    protected function configure(): void
    {
        $this->setName('update:db');
        $this->setDescription('Applies pending updates to the database after the code update.');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatic yes to prompts; assume "yes" as answer to all prompts and run non-interactively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $autoConfirm = (bool) $input->getOption('yes');

        $credentials = $this->readCredentials($output);
        if ($credentials === null) {
            return Command::FAILURE;
        }

        if (!Omeka::authenticate($credentials['email'], $credentials['password'])) {
            $output->writeln('<error>Could not authenticate with Omeka S using the credentials in config.json.</error>');
            return Command::FAILURE;
        }

        $output->writeln('Checking for database updates...');
        $dbUpdater = new DbUpdater();
        $coreUpdate = $dbUpdater->auditCore();
        $modulesUpdate = $dbUpdater->auditModules();

        if ($coreUpdate) {
            $output->writeln('Core update available: ' . $this->formatVersions($coreUpdate['from'], $coreUpdate['to']));
        } else {
            $output->writeln('Core is up to date.');
        }
        if ($modulesUpdate) {
            $output->writeln('Module updates available:');
            foreach ($modulesUpdate as $moduleID => $update) {
                $output->writeln($moduleID . ': ' . $this->formatVersions($update['from'], $update['to']));
            }
        } else {
            $output->writeln('All modules are up to date.');
        }

        if (!$coreUpdate && !$modulesUpdate) {
            $output->writeln('<info>No updates available. The database is up to date.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>CAUTION: Updating the DB may disrupt your current installation. Before proceeding, please ensure you have a complete backup of the database.</error>');

        if (!$this->confirm($input, $output, $autoConfirm)) {
            return Command::SUCCESS;
        }

        $hasErrors = false;

        if ($coreUpdate) {
            $output->writeln('Applying core updates...');
            try {
                $dbUpdater->updateCore();
                $output->writeln('<info>Core update applied successfully.</info>');
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                $hasErrors = true;
            }
        }

        if ($modulesUpdate) {
            $moduleErrors = false;
            foreach (array_keys($modulesUpdate) as $moduleID) {
                $output->writeln("Applying update for module {$moduleID}...");
                try {
                    $dbUpdater->updateModule($moduleID);
                } catch (\RuntimeException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    $moduleErrors = true;
                }
            }
            if ($moduleErrors) {
                $output->writeln('<comment>Some module updates failed. Please try to manually update the module via the UI.</comment>');
                $hasErrors = true;
            } else {
                $output->writeln('<info>All module updates applied successfully.</info>');
            }
        }

        if ($hasErrors) {
            $output->writeln('<comment>The database has been updated with some errors. Please check the messages above.</comment>');
            return Command::FAILURE;
        }

        $output->writeln('<info>The database has been updated successfully.</info>');

        return Command::SUCCESS;
    }
}
