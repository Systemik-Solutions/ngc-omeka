<?php

namespace App\Command;

use App\Distribution\Auditor;
use App\Distribution\CodeUpdater;
use App\Distribution\Inspector;
use App\Distribution\Manifest;
use App\Omeka;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Updates themes on their own.
 *
 * Themes are files and nothing else: Omeka S registers whatever directories exist under themes/, so
 * there is no install or upgrade step and no database work. That is why this command needs neither
 * config.json nor a database connection, and will run against an installation whose database is
 * down - unlike update:core and update:module.
 *
 * With no arguments this covers every theme in distribution.json, and only those.
 */
class UpdateThemeCommand extends AbstractUpdateCommand
{
    protected function configure(): void
    {
        $this->setName('update:theme');
        $this->setDescription('Updates the distribution themes only. Does not touch the database.');
        $this->addArgument('themes', InputArgument::IS_ARRAY, 'The IDs of the themes to update, separated by spaces. Defaults to every theme in distribution.json.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-download and re-extract the themes even when they are already at the version in distribution.json.');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatic yes to prompts; assume "yes" as answer to all prompts and run non-interactively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $autoConfirm = (bool) $input->getOption('yes');
        $rootDir = $this->getRootDir();

        $output->writeln('Checking for theme updates...');
        try {
            $manifest = new Manifest($rootDir);
            $ids = $manifest->resolveThemeIds($input->getArgument('themes'));
            $plan = (new Auditor($manifest, new Inspector($rootDir)))->auditThemes($ids, $force);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($plan)) {
            $output->writeln('<info>No updates available. The selected themes are up to date.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('Theme updates available:');
        foreach ($plan as $id => $entry) {
            if ($entry['code']['forced']) {
                $output->writeln($id . ': forced re-download of ' . $entry['code']['to']);
            } else {
                $output->writeln($id . ': ' . $this->formatVersions($entry['code']['from'], $entry['code']['to']));
            }
        }

        $output->writeln('<error>CAUTION: Updating may disrupt your current installation. Before proceeding, please ensure you have a complete backup of the Omeka S codebase, in particular the "public/themes" directory. Any local changes to the themes being updated will be lost.</error>');

        if (!$this->confirm($input, $output, $autoConfirm)) {
            return Command::SUCCESS;
        }

        $hasErrors = false;

        // Nothing here has any reason to bootstrap Omeka, so keep it locked for the whole run.
        $codeUpdater = new CodeUpdater($rootDir, fn ($message) => $output->writeln($message));
        Omeka::lockBootstrap();
        try {
            foreach ($plan as $id => $entry) {
                try {
                    $codeUpdater->updateTheme($id, $manifest->getTheme($id));
                } catch (\RuntimeException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    $hasErrors = true;
                }
            }
        } finally {
            Omeka::unlockBootstrap();
        }

        if ($hasErrors) {
            $output->writeln('<comment>Some theme updates failed. Please check the messages above.</comment>');
            return Command::FAILURE;
        }

        $output->writeln('<info>The themes have been updated successfully.</info>');

        return Command::SUCCESS;
    }
}
