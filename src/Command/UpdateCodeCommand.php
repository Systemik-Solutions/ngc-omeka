<?php
namespace App\Command;

use App\Helper;
use App\Omeka;
use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class UpdateCodeCommand extends Command
{
    private array $coreUpdate = [];

    private array $modulesUpdate = [];

    private array $themesUpdate = [];

    private bool $hasMapping1 = false;

    protected function configure(): void
    {
        $this->setName('update:code');
        $this->setDescription('Update NGC Omeka S distribution code.');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatic yes to prompts; assume "yes" as answer to all prompts and run non-interactively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $autoConfirm = $input->getOption('yes');
        $rootDir = dirname(__DIR__, 2);
        $distFile = $rootDir . '/distribution.json';
        $configPath = $rootDir . '/config/config.json';
        $publicDir = $rootDir . '/public';

        // Read distribution.json
        if (!file_exists($distFile)) {
            $output->writeln('<error>distribution.json not found.</error>');
            return Command::FAILURE;
        }
        $manifest = json_decode(file_get_contents($distFile), true);

        // Read config.json
        if (!file_exists($configPath)) {
            $output->writeln('<error>config.json not found.</error>');
            return Command::FAILURE;
        }
        $config = json_decode(file_get_contents($configPath), true);

        // Get the user credentials.
        if (!isset($config['admin']['email']) || !isset($config['admin']['password'])) {
            $output->writeln('<error>Admin credentials not found in config.json.</error>');
            return Command::FAILURE;
        }

        // Authenticate to Omeka S
        Omeka::authenticate($config['admin']['email'], $config['admin']['password']);

        // Audit current installation.
        $output->writeln('Checking for updates...');
        $this->audit($manifest);
        if (!empty($this->coreUpdate)) {
            $output->writeln('Core update available: ' . $this->coreUpdate['from'] . ' => ' . $this->coreUpdate['to']);
        } else {
            $output->writeln('Core is up to date.');
        }
        if (!empty($this->modulesUpdate)) {
            $output->writeln('Module updates available:');
            foreach ($this->modulesUpdate as $moduleID => $update) {
                // Throw an error if the "MappingExtensions" module update is from version 1.0.0.
                if ($moduleID === 'MappingExtensions' && $this->hasMapping1) {
                    $output->writeln('<error>Module "MappingExtensions" update from version 1.0.0 requires a manual update before running the distribution update command. Please refer to the README.md and https://github.com/Systemik-Solutions/OmekaS-MappingExtensions#upgrading-from-100-to-101 for more details.</error>');
                    return Command::FAILURE;
                }
                $output->writeln($moduleID . ': ' . $update['from'] . ' => ' . $update['to']);
            }
        } else {
            $output->writeln('All modules are up to date.');
        }
        if (!empty($this->themesUpdate)) {
            $output->writeln('Theme updates available:');
            foreach ($this->themesUpdate as $themeID => $update) {
                $output->writeln($themeID . ': ' . $update['from'] . ' => ' . $update['to']);
            }
        } else {
            $output->writeln('All themes are up to date.');
        }

        if (empty($this->coreUpdate) && empty($this->modulesUpdate) && empty($this->themesUpdate)) {
            $output->writeln('<info>No updates available. The distribution is up to date.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>CAUTION: Updating may disrupt your current installation. Before proceeding, please ensure you have a complete backup of both the Omeka S codebase (especially the "public" directory) and the database.</error>');

        if (!$autoConfirm) {
            $qHelper = new QuestionHelper();
            $question = new ConfirmationQuestion('Would you like to continue? (y|n)', false);

            if (!$qHelper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        // Download the core update.
        if (!$this->downloadCore($output, $manifest, $publicDir)) {
            return Command::FAILURE;
        }

        $hasErrors = false;

        // Download the modules updates.
        if (!$this->downloadModules($output, $manifest, $publicDir)) {
            $hasErrors = true;
        }

        // Download the themes updates.
        if (!$this->downloadThemes($output, $manifest, $publicDir)) {
            $hasErrors = true;
        }

        if ($hasErrors) {
            $output->writeln('<comment>The distribution code has been updated with some errors. Please check the messages above. Please run the "update:db" command to finish the update.</comment>');
        } else {
            $output->writeln('<info>The distribution code has been updated successfully. Please run the "update:db" command to finish the update.</info>');
        }

        return Command::SUCCESS;
    }

    private function audit($manifest): void
    {
        $serviceManager = Omeka::getApp()->getServiceManager();

        // Check core update.
        $status = $serviceManager->get('Omeka\Status');
        $currentVersion = $status->getInstalledVersion();
        $latestVersion = $manifest['core']['version'];
        if (version_compare($currentVersion, $latestVersion, '<')) {
            $this->coreUpdate = [
                'from' => $currentVersion,
                'to' => $latestVersion,
            ];
        }

        // Check modules update.
        /**
         * @var \Omeka\Module\Manager $moduleManager
         */
        $moduleManager = $serviceManager->get('Omeka\ModuleManager');

        // Check whether it involves an update of the "MappingExtensions" module from version 1.0.0.
        $module = $moduleManager->getModule('Mapping');
        if ($module && $module->getIni('version') === '1.0.0') {
            $this->hasMapping1 = true;
        }

        foreach ($manifest['modules'] as $moduleInfo) {
            $moduleID = $moduleInfo['name'];
            $latestVersion = $moduleInfo['version'];
            $module = $moduleManager->getModule($moduleID);
            if ($module) {
                $currentVersion = $module->getIni('version');
                if (version_compare($currentVersion, $latestVersion, '<')) {
                    $this->modulesUpdate[$moduleID] = [
                        'from' => $currentVersion,
                        'to' => $latestVersion,
                    ];
                }
            } else {
                // Module not installed.
                $this->modulesUpdate[$moduleID] = [
                    'from' => null,
                    'to' => $latestVersion,
                ];
            }
        }

        // Check themes update.
        /**
         * @var \Omeka\Site\Theme\Manager $themeManager
         */
        $themeManager = $serviceManager->get('Omeka\Site\ThemeManager');
        foreach ($manifest['themes'] as $themeInfo) {
            $themeID = $themeInfo['name'];
            $latestVersion = $themeInfo['version'];
            if ($themeManager->isRegistered($themeID)) {
                $theme = $themeManager->getTheme($themeID);
                $currentVersion = $theme->getIni('version');
                if (version_compare($currentVersion, $latestVersion, '<')) {
                    $this->themesUpdate[$themeID] = [
                        'from' => $currentVersion,
                        'to' => $latestVersion,
                    ];
                }
            } else {
                // Theme not installed.
                $this->themesUpdate[$themeID] = [
                    'from' => null,
                    'to' => $latestVersion,
                ];
            }
        }
    }

    private function downloadCore(OutputInterface $output, $manifest, $publicDir): bool
    {
        if (!empty($this->coreUpdate)) {
            $client = new Client();
            $coreUrl = $manifest['core']['url'];
            $coreVersion = $manifest['core']['version'] ?? 'unknown';
            $output->writeln("Downloading Omeka S {$coreVersion}...");
            $tmpZip = tempnam(sys_get_temp_dir(), 'omeka_core_') . '.zip';
            try {
                $client->request('GET', $coreUrl, ['sink' => $tmpZip]);
            } catch (\Exception $e) {
                $output->writeln('<error>Download failed: ' . $e->getMessage() . '</error>');
                return false;
            }
            $output->writeln('Extracting the package...');
            $keepDirs = ['config', 'files', 'modules', 'themes', 'logs'];

            // Delete everything in the public directory except the keep directories.
            $items = scandir($publicDir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (in_array($item, $keepDirs)) {
                    continue;
                }
                $fullPath = $publicDir . '/' . $item;
                if (is_dir($fullPath)) {
                    Helper::rrmdir($fullPath);
                } else {
                    unlink($fullPath);
                }
            }

            // Extract the zip to the temp directory.
            $tempDir = $publicDir . '/update_temp';
            // Delete tempDir if it exists.
            if (is_dir($tempDir)) {
                Helper::rrmdir($tempDir);
            }
            try {
                Helper::extractZipTopLevelDir($tmpZip, $tempDir);
            } catch (\Exception $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                unlink($tmpZip);
                if (is_dir($tempDir)) {
                    Helper::rrmdir($tempDir);
                }
                return false;
            }
            // Move everything from tempDir to publicDir except the keep directories.
            $extractedItems = scandir($tempDir);
            foreach ($extractedItems as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (in_array($item, $keepDirs)) {
                    continue;
                }
                rename($tempDir . '/' . $item, $publicDir . '/' . $item);
            }
            // Clean up.
            unlink($tmpZip);
            Helper::rrmdir($tempDir);

            $this->coreUpdate['downloaded'] = true;
            $output->writeln('Core update has been unpacked successfully.');
        }

        return true;
    }

    private function downloadModules(OutputInterface $output, $manifest, $publicDir): bool
    {
        $hasErrors = false;
        if (!empty($this->modulesUpdate)) {
            $client = new Client();
            foreach ($manifest['modules'] as $moduleInfo) {
                $moduleID = $moduleInfo['name'];
                if (isset($this->modulesUpdate[$moduleID])) {
                    $moduleUrl = $moduleInfo['url'];
                    $moduleVersion = $moduleInfo['version'] ?? 'unknown';
                    $output->writeln("Downloading module: {$moduleID}({$moduleVersion})...");
                    $tmpZip = tempnam(sys_get_temp_dir(), 'omeka_module_') . '.zip';
                    try {
                        $client->request('GET', $moduleUrl, ['sink' => $tmpZip]);
                    } catch (\Exception $e) {
                        $output->writeln('<error>Download failed: ' . $e->getMessage() . '</error>');
                        $hasErrors = true;
                        continue;
                    }
                    $output->writeln("Extracting module {$moduleID}...");
                    $modulesDir = $publicDir . '/modules/' . $moduleID;
                    if (is_dir($modulesDir)) {
                        // Rename the existing module directory as a backup.
                        $backupDir = $modulesDir . '_bkp';
                        if (is_dir($backupDir)) {
                            Helper::rrmdir($backupDir);
                        }
                        rename($modulesDir, $backupDir);
                    }

                    try {
                        Helper::extractZip($tmpZip, $publicDir . '/modules');
                    } catch (\Exception $e) {
                        $output->writeln('<error>' . $e->getMessage() . '</error>');
                        unlink($tmpZip);
                        if (is_dir($modulesDir)) {
                            Helper::rrmdir($modulesDir);
                        }
                        if (isset($backupDir) && is_dir($backupDir)) {
                            rename($backupDir, $modulesDir);
                        }
                        $hasErrors = true;
                        continue;
                    }
                    // Clean up.
                    unlink($tmpZip);
                    if (isset($backupDir) && is_dir($backupDir)) {
                        Helper::rrmdir($backupDir);
                    }
                    $this->modulesUpdate[$moduleID]['downloaded'] = true;
                    $output->writeln("Module {$moduleID} update has been unpacked successfully.");
                }
            }
        }
        return !$hasErrors;
    }

    private function downloadThemes(OutputInterface $output, $manifest, $publicDir): bool
    {
        $hasErrors = false;
        if (!empty($this->themesUpdate)) {
            $client = new Client();
            foreach ($manifest['themes'] as $themeInfo) {
                $themeID = $themeInfo['name'];
                if (isset($this->themesUpdate[$themeID])) {
                    $themeUrl = $themeInfo['url'];
                    $themeVersion = $themeInfo['version'] ?? 'unknown';
                    $output->writeln("Downloading theme: {$themeID}({$themeVersion})...");
                    $tmpZip = tempnam(sys_get_temp_dir(), 'omeka_theme_') . '.zip';
                    try {
                        $client->request('GET', $themeUrl, ['sink' => $tmpZip]);
                    } catch (\Exception $e) {
                        $output->writeln('<error>Download failed: ' . $e->getMessage() . '</error>');
                        $hasErrors = true;
                        continue;
                    }
                    $output->writeln("Extracting theme {$themeID}...");
                    $themesDir = $publicDir . '/themes/' . $themeID;
                    if (is_dir($themesDir)) {
                        // Rename the existing theme directory as a backup.
                        $backupDir = $themesDir . '_bkp';
                        if (is_dir($backupDir)) {
                            Helper::rrmdir($backupDir);
                        }
                        rename($themesDir, $backupDir);
                    }

                    try {
                        Helper::extractZip($tmpZip, $publicDir . '/themes');
                    } catch (\Exception $e) {
                        $output->writeln('<error>' . $e->getMessage() . '</error>');
                        unlink($tmpZip);
                        if (is_dir($themesDir)) {
                            Helper::rrmdir($themesDir);
                        }
                        if (isset($backupDir) && is_dir($backupDir)) {
                            rename($backupDir, $themesDir);
                        }
                        $hasErrors = true;
                        continue;
                    }
                    // Clean up.
                    unlink($tmpZip);
                    if (isset($backupDir) && is_dir($backupDir)) {
                        Helper::rrmdir($backupDir);
                    }
                    $this->themesUpdate[$themeID]['downloaded'] = true;
                    $output->writeln("Theme {$themeID} update has been unpacked successfully.");
                }
            }
        }
        return !$hasErrors;
    }
}
