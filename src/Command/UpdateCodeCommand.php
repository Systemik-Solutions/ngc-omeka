<?php

namespace App\Command;

use App\Distribution\Auditor;
use App\Distribution\CodeUpdater;
use App\Distribution\Inspector;
use App\Distribution\Manifest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Downloads the core, module and theme updates, without touching the database.
 *
 * Deliberately audits the installation *without* bootstrapping Omeka S: PHP cannot reload a class
 * once it is declared, so loading the core and module classes here would leave the "update:db" step
 * running against the code this command is about to replace. Every read goes through
 * App\Distribution\Inspector instead.
 *
 * For updating a single component, see update:core, update:module and update:theme, which each pair
 * the download with the matching database work.
 */
class UpdateCodeCommand extends AbstractUpdateCommand
{
    private bool $cancelled = false;

    private bool $chained = false;

    /**
     * Mark this run as part of the combined "update" command.
     *
     * Suppresses the notice telling the user to run "update:db", which that command does for them.
     */
    public function setChained(bool $chained): void
    {
        $this->chained = $chained;
    }

    /**
     * Check whether the user declined the update at the confirmation prompt.
     *
     * Lets the "update" command tell a declined run apart from a completed one, since both report
     * success.
     */
    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    protected function configure(): void
    {
        $this->setName('update:code');
        $this->setDescription('Update NGC Omeka S distribution code.');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatic yes to prompts; assume "yes" as answer to all prompts and run non-interactively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        $output->writeln('Checking for updates...');
        try {
            $manifest = new Manifest($rootDir);
            $auditor = new Auditor($manifest, $inspector);

            $coreUpdate = $auditor->auditCore()['code'];
            // Only the code side matters here; the database side is "update:db"'s concern.
            $modulesUpdate = $this->codeOnly($auditor->auditModules($manifest->resolveModuleIds(null)));
            $themesUpdate = $this->codeOnly($auditor->auditThemes($manifest->resolveThemeIds(null)));
            $hasLegacyMapping = $auditor->hasLegacyMapping();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($coreUpdate) {
            $output->writeln('Core update available: ' . $this->formatVersions($coreUpdate['from'], $coreUpdate['to']));
        } else {
            $output->writeln('Core is up to date.');
        }
        if ($modulesUpdate) {
            $output->writeln('Module updates available:');
            foreach ($modulesUpdate as $moduleID => $update) {
                // The "Mapping" to "MappingExtensions" rename cannot be automated.
                if ($moduleID === 'MappingExtensions' && $hasLegacyMapping) {
                    $output->writeln('<error>Module "MappingExtensions" update from version 1.0.0 requires a manual update before running the distribution update command. Please refer to the README.md and https://github.com/Systemik-Solutions/OmekaS-MappingExtensions#upgrading-from-100-to-101 for more details.</error>');
                    return Command::FAILURE;
                }
                $output->writeln($moduleID . ': ' . $this->formatVersions($update['from'], $update['to']));
            }
        } else {
            $output->writeln('All modules are up to date.');
        }
        if ($themesUpdate) {
            $output->writeln('Theme updates available:');
            foreach ($themesUpdate as $themeID => $update) {
                $output->writeln($themeID . ': ' . $this->formatVersions($update['from'], $update['to']));
            }
        } else {
            $output->writeln('All themes are up to date.');
        }

        if (!$coreUpdate && !$modulesUpdate && !$themesUpdate) {
            $output->writeln('<info>No updates available. The distribution is up to date.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>CAUTION: Updating may disrupt your current installation. Before proceeding, please ensure you have a complete backup of both the Omeka S codebase (especially the "public" directory) and the database.</error>');

        if (!$this->confirm($input, $output, $autoConfirm)) {
            $this->cancelled = true;
            return Command::SUCCESS;
        }

        $codeUpdater = new CodeUpdater($rootDir, fn ($message) => $output->writeln($message));

        // A failed core update leaves public/ in an unknown state, so it stops the run outright
        // rather than carrying on into the modules and themes.
        if ($coreUpdate) {
            try {
                $codeUpdater->updateCore($manifest->getCore());
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        $hasErrors = false;
        foreach (array_keys($modulesUpdate) as $moduleID) {
            try {
                $codeUpdater->updateModule($moduleID, $manifest->getModule($moduleID));
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                $hasErrors = true;
            }
        }
        foreach (array_keys($themesUpdate) as $themeID) {
            try {
                $codeUpdater->updateTheme($themeID, $manifest->getTheme($themeID));
            } catch (\RuntimeException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                $hasErrors = true;
            }
        }

        $followUp = $this->chained ? '' : ' Please run the "update:db" command to finish the update.';
        if ($hasErrors) {
            $output->writeln('<comment>The distribution code has been updated with some errors. Please check the messages above.' . $followUp . '</comment>');
            return Command::FAILURE;
        }

        $output->writeln('<info>The distribution code has been updated successfully.' . $followUp . '</info>');

        return Command::SUCCESS;
    }

    /**
     * Reduce an audit plan to the entries that need new code.
     *
     * The Auditor also reports components whose files are current but whose database is behind. Those
     * are nothing for this command to download.
     */
    private function codeOnly(array $plan): array
    {
        $codeUpdates = [];
        foreach ($plan as $id => $entry) {
            if ($entry['code']) {
                $codeUpdates[$id] = $entry['code'];
            }
        }
        return $codeUpdates;
    }
}
