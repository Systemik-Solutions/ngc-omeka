<?php
namespace App\Command;

use App\Helper;
use App\Omeka;
use GuzzleHttp\Client;
use Omeka\Mvc\Controller\Plugin\Api;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Laminas\ServiceManager\ServiceManager;

class InstallCommand extends Command
{

    protected function configure(): void
    {
        $this->setName('install');
        $this->setDescription('Installs NGC Omeka S distribution.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        // Remove existing public directory
        if (is_dir($publicDir)) {
            $output->writeln('Removing existing public directory...');
            Helper::rrmdir($publicDir);
        }

        // Install Omeka S core code
        if (!$this->downloadComponents($output, $manifest, $publicDir)) {
            return Command::FAILURE;
        }

        // Pre Omeka S installation steps
        if (!$this->preOmekaInstall($output, $config, $rootDir)) {
            return Command::FAILURE;
        }

        // Install Omeka S
        if (!$this->installOmeka($output, $config)) {
            return Command::FAILURE;
        }

        $hasErrors = false;

        // Install modules
        if (!$this->installModules($output, $manifest, $publicDir)) {
            $hasErrors = true;
        }

        // Reload Omeka application to recognize newly installed modules.
        Omeka::reloadApp();
        Omeka::authenticate();

        // Create site
        if (!$this->createSite($output, $config)) {
            $hasErrors = true;
        }

        // Install vocabularies
        if (!$this->installVocabularies($output, $manifest, $rootDir)) {
            $hasErrors = true;
        }

        // Install taxonomies
        if (!$this->installTaxonomies($output, $manifest, $rootDir)) {
            $hasErrors = true;
        }

        // Install resource templates
        if (!$this->installResourceTemplates($output, $manifest, $rootDir)) {
            $hasErrors = true;
        }

        if ($hasErrors) {
            $output->writeln('<comment>The distribution has been installed with some errors. Please check the messages above.</comment>');
        } else {
            $output->writeln('<info>The distribution has been installed successfully.</info>');
        }
        return Command::SUCCESS;
    }

    private function downloadComponents(OutputInterface $output, array $manifest, string $publicDir): bool
    {
        $client = new Client();
        // Download Omeka S core
        if (isset($manifest['core'])) {
            $coreUrl = $manifest['core']['url'];
            if (empty($coreUrl)) {
                $output->writeln('<error>Core URL not found in distribution.json.</error>');
                return false;
            }
            $coreVersion = $manifest['core']['version'] ?? 'unknown';
            $output->writeln("Downloading Omeka S {$coreVersion}...");
            $tmpZip = tempnam(sys_get_temp_dir(), 'omeka_core_') . '.zip';
            try {
                $client->request('GET', $coreUrl, ['sink' => $tmpZip]);
            } catch (\Exception $e) {
                $output->writeln('<error>Download failed: ' . $e->getMessage() . '</error>');
                return false;
            }

            $output->writeln('Extracting Omeka S core to public directory...');
            try {
                Helper::extractZipTopLevelDir($tmpZip, $publicDir);
                $output->writeln("<info>Omeka S {$coreVersion} has been downloaded and extracted.</info>");
            } catch (\Exception $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                unlink($tmpZip);
                return false;
            }
            unlink($tmpZip);
        }

        // Download modules.
        if (!empty($manifest['modules']) && is_array($manifest['modules'])) {
            $modulesDir = $publicDir . '/modules';

            foreach ($manifest['modules'] as $module) {
                $moduleName = $module['name'] ?? null;
                $moduleUrl = $module['url'] ?? null;
                $moduleVersion = $module['version'] ?? 'unknown';
                if (empty($moduleName) || empty($moduleUrl)) {
                    $output->writeln('<error>Module name or URL missing in distribution.json.</error>');
                    continue;
                }

                $output->writeln("Downloading module: {$moduleName}({$moduleVersion})...");
                // Download the module zip.
                $tmpZip = tempnam(sys_get_temp_dir(), 'omeka_module_') . '.zip';
                try {
                    $client->request('GET', $moduleUrl, ['sink' => $tmpZip]);
                } catch (\Exception $e) {
                    $output->writeln('<error>Download failed: ' . $e->getMessage() . '</error>');
                    continue;
                }
                // Extract the module to the modules directory.
                try {
                    Helper::extractZip($tmpZip, $modulesDir);
                } catch (\Exception $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    unlink($tmpZip);
                    continue;
                }
                unlink($tmpZip);
                $output->writeln("<info>Module {$moduleName}({$moduleVersion}) downloaded successfully.</info>");
            }
        }

        // Download themes.
        if (!empty($manifest['themes']) && is_array($manifest['themes'])) {
            $themesDir = $publicDir . '/themes';

            foreach ($manifest['themes'] as $theme) {
                $themeName = $theme['name'] ?? null;
                $themeUrl = $theme['url'] ?? null;
                $themeVersion = $theme['version'] ?? 'unknown';
                if (empty($themeName) || empty($themeUrl)) {
                    $output->writeln('<error>Theme name or URL missing in distribution.json.</error>');
                    continue;
                }

                $output->writeln("Downloading theme: {$themeName}({$themeVersion})...");
                // Download the theme zip.
                $tmpZip = tempnam(sys_get_temp_dir(), 'omeka_theme_') . '.zip';
                try {
                    $client->request('GET', $themeUrl, ['sink' => $tmpZip]);
                } catch (\Exception $e) {
                    $output->writeln('<error>Download failed: ' . $e->getMessage() . '</error>');
                    continue;
                }
                // Extract the theme to the themes directory.
                try {
                    Helper::extractZip($tmpZip, $themesDir);
                } catch (\Exception $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    unlink($tmpZip);
                    continue;
                }
                unlink($tmpZip);
                $output->writeln("<info>Theme {$themeName}({$themeVersion}) downloaded successfully.</info>");
            }
        }
        return true;
    }

    private function preOmekaInstall(OutputInterface $output, array $config, string $rootDir): bool
    {

        $dbIniPath = $rootDir . '/public/config/database.ini';
        if (empty($config['db'])) {
            $output->writeln('<error>DB config not found in config.json.</error>');
            return false;
        }
        $db = $config['db'];
        $dbHost = $db['host'] ?? 'localhost';
        $dbPort = $db['port'] ?? '3306';
        $dbUser = $db['username'] ?? 'root';
        $dbPassword = $db['password'] ?? '';
        $dbName = $db['database'] ?? 'omeka_s';

        $iniContent = <<<INI
host = "{$dbHost}"
port = "{$dbPort}"
user = "{$dbUser}"
password = "{$dbPassword}"
dbname = "{$dbName}"
INI;

        if (file_put_contents($dbIniPath, $iniContent) === false) {
            $output->writeln('<error>Failed to write database.ini.</error>');
            return false;
        }

        $output->writeln('<info>database.ini populated successfully.</info>');

        $localConfigSrc = $rootDir . '/config/local.config.php';
        $localConfigDest = $rootDir . '/public/config/local.config.php';
        if (file_exists($localConfigSrc)) {
            if (!copy($localConfigSrc, $localConfigDest)) {
                $output->writeln('<error>Failed to copy local.config.php.</error>');
                return false;
            }
            $output->writeln('<info>local.config.php copied successfully.</info>');
        }

        // Attempt to set permissions for files and logs directories (in Linux).
        if (PHP_OS_FAMILY === 'Linux') {
            $this->setDirPermissions($output, $config, $rootDir);
        }

        return true;
    }

    private function setDirPermissions(OutputInterface $output, array $config, string $rootDir): bool
    {
        $apacheUser = $config['apache_user'] ?? 'www-data';
        $filesDir = $rootDir . '/public/files';
        $logsDir = $rootDir . '/public/logs';
        // Set the permission to the files and logs directories.
        $dirs = [$filesDir, $logsDir];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $output->writeln("Setting permissions for directory: {$dir}...");
                exec("chmod -R 775 " . escapeshellarg($dir), $cmdOutput, $resultCode);
                if ($resultCode !== 0) {
                    $output->writeln("<comment>Failed to set permissions. Please set it manually.</comment>");
                }
                exec("sudo chown -R {$apacheUser} " . escapeshellarg($dir), $cmdOutput, $resultCode);
                if ($resultCode !== 0) {
                    $output->writeln("<comment>Failed to change owner to {$apacheUser}. Please set it manually.</comment>");
                }
                $output->writeln("<info>Permissions set for directory: {$dir}.</info>");
            } else {
                $output->writeln("<comment>Directory {$dir} does not exist. Skipping permission setting.</comment>");
            }
        }
        return true;
    }

    private function installOmeka(OutputInterface $output, array $config): bool
    {
        $omeka = Omeka::getApp();
        /**
         * @var ServiceManager $serviceManager
         */
        $serviceManager = $omeka->getServiceManager();
        $status = $serviceManager->get('Omeka\Status');
        if ($status->isInstalled()) {
            $output->writeln('<comment>Omeka S is already installed. Skip...</comment>');
            return true;
        } else {
            if (empty($config['admin'])) {
                $output->writeln('<error>Admin user config not found in config.json.</error>');
                return false;
            }
            $adminUser = $config['admin'];
            $adminName = $adminUser['name'] ?? 'admin';
            $adminEmail = $adminUser['email'] ?? 'admin@example.com';
            $adminPassword = $adminUser['password'] ?? 'password';
            $instanceTitle = $config['title'] ?? 'Omeka S';
            $instanceTimezone = $config['timezone'] ?? 'UTC';

            $output->writeLn("Installing Omeka S...");
            /**
             * @var \Omeka\Installation\Installer $installer
             */
            $installer = $serviceManager->get('Omeka\Installer');
            $installer->registerVars(
                'Omeka\Installation\Task\CreateFirstUserTask',
                [
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'password-confirm' => [
                        'password' => $adminPassword,
                        'password-confirm' => $adminPassword,
                    ],
                ]
            );
            $installer->registerVars(
                'Omeka\Installation\Task\AddDefaultSettingsTask',
                [
                    'administrator_email' => $adminEmail,
                    'installation_title' => $instanceTitle,
                    'time_zone' => $instanceTimezone,
                    'locale' => '',
                ]
            );
            if ($installer->install()) {
                // Authenticate the admin user for further operations.
                Omeka::authenticate($adminEmail, $adminPassword);
                $output->writeln('<info>Omeka S has been installed successfully.</info>');
            } else {
                $output->writeln('<error>There were errors during installation.</error>');
                foreach ($installer->getErrors() as $error) {
                    $output->writeln('<error>' . $error . '</error>');
                }
                return false;
            }
        }

        return true;
    }

    private function installModules(OutputInterface $output, array $manifest, string $publicDir): bool
    {
        if (!empty($manifest['modules']) && is_array($manifest['modules'])) {
            /**
             * @var ServiceManager $serviceManager
             */
            $serviceManager = Omeka::getApp()->getServiceManager();
            /**
             * @var \Omeka\Module\Manager $moduleManager
             */
            $moduleManager = $serviceManager->get('Omeka\ModuleManager');

            foreach ($manifest['modules'] as $module) {
                $moduleName = $module['name'] ?? null;
                $moduleVersion = $module['version'] ?? 'unknown';
                // Install and activate the module.
                $output->writeln("Installing module: {$moduleName}({$moduleVersion})...");
                $module = $moduleManager->getModule($moduleName);
                if (!$module) {
                    $output->writeln("<comment>Module {$moduleName} could not be found. Skip...</comment>");
                    continue;
                }
                if ($module->getState() !== \Omeka\Module\Manager::STATE_NOT_INSTALLED) {
                    $output->writeln("<comment>Module {$moduleName} is already installed. Skip...</comment>");
                    continue;
                }
                try {
                    $moduleManager->install($module);
                } catch (\Exception $e) {
                    $output->writeln('<error>Module installation failed: ' . $e->getMessage() . '</error>');
                    continue;
                }
                $output->writeln("<info>Module {$moduleName}({$moduleVersion}) installed successfully.</info>");
            }
        }

        return true;
    }

    private function createSite(OutputInterface $output, array $config): bool
    {
        if (isset($config['site'])) {
            $siteTitle = $config['site']['title'] ?? 'My Site';
            $siteSlug = $config['site']['slug'] ?? '';
            $siteSummary = $config['site']['summary'] ?? '';
            $siteTheme = $config['site']['theme'] ?? 'default';

            $serviceManager = Omeka::getApp()->getServiceManager();
            $api = $serviceManager->get('Omeka\ApiManager');
            try {
                $response = $api->create('sites', [
                    'o:title' => $siteTitle,
                    'o:slug' => $siteSlug,
                    'o:summary' => $siteSummary,
                    'o:theme' => $siteTheme,
                ]);
                $output->writeln("<info>Site '{$siteTitle}' created successfully.</info>");
            } catch (\Exception $e) {
                $output->writeln('<error>Site creation failed: ' . $e->getMessage() . '</error>');
                return false;
            }
        }
        return true;
    }

    private function installVocabularies(OutputInterface $output, array $manifest, string $rootDir): bool
    {
        if (!empty($manifest['vocabularies']) && is_array($manifest['vocabularies'])) {
            $serviceManager = Omeka::getApp()->getServiceManager();
            /**
             * @var \Omeka\Stdlib\RdfImporter $importer
             */
            $importer = $serviceManager->get('Omeka\RdfImporter');
            foreach ($manifest['vocabularies'] as $vocabulary) {
                $vocabLabel = $vocabulary['label'] ?? null;
                $vocabComment = $vocabulary['comment'] ?? '';
                $vocabUri = $vocabulary['namespace_uri'] ?? null;
                $vocabPrefix = $vocabulary['prefix'] ?? null;
                $vocabFile = $vocabulary['file'] ?? null;
                $vocabFormat = $vocabulary['format'] ?? 'guess';
                if (empty($vocabLabel) || empty($vocabUri) || empty($vocabPrefix) || empty($vocabFile)) {
                    $output->writeln('<error>Vocabulary information incomplete in distribution.json.</error>');
                    continue;
                }
                $vocabFilePath = $rootDir . '/vocabularies/' . $vocabFile;
                if (!file_exists($vocabFilePath)) {
                    $output->writeln("<error>Vocabulary file {$vocabFile} not found.</error>");
                    continue;
                }
                $output->writeln("Importing vocabulary: {$vocabLabel}...");
                try {
                    $importer->import('file', [
                        'o:label' => $vocabLabel,
                        'o:comment' => $vocabComment,
                        'o:namespace_uri' => $vocabUri,
                        'o:prefix' => $vocabPrefix,
                    ], [
                        'format' => $vocabFormat,
                        'file' => $vocabFilePath,
                    ]);
                    $output->writeln("<info>Vocabulary '{$vocabLabel}' imported successfully.</info>");
                } catch (\Exception $e) {
                    $output->writeln('<error>Vocabulary import failed: ' . $e->getMessage() . '</error>');
                    continue;
                }
            }
        }

        return true;
    }

    private function installTaxonomies(OutputInterface $output, array $manifest, string $rootDir): bool
    {
        if (!empty($manifest['taxonomies']) && is_array($manifest['taxonomies'])) {
            $serviceManager = Omeka::getApp()->getServiceManager();
            $api = $serviceManager->get('Omeka\ApiManager');
            foreach ($manifest['taxonomies'] as $vocabulary) {
                $vocabLabel = $vocabulary['label'] ?? null;
                $vocabFile = $vocabulary['file'] ?? null;
                if (empty($vocabLabel) || empty($vocabFile)) {
                    $output->writeln('<error>Taxonomy information incomplete in distribution.json.</error>');
                    continue;
                }
                $vocabFilePath = $rootDir . '/taxonomies/' . $vocabFile;
                if (!file_exists($vocabFilePath)) {
                    $output->writeln("<error>Taxonomy file {$vocabFile} not found.</error>");
                    continue;
                }
                $output->writeln("Importing taxonomy: {$vocabLabel}...");
                $vocabData = json_decode(file_get_contents($vocabFilePath), true);
                if (!isset($vocabData['o:lang'])) {
                    $vocabData['o:lang'] = '';
                }
                try {
                    $api->create('custom_vocabs', $vocabData);
                    $output->writeln("<info>Taxonomy '{$vocabLabel}' imported successfully.</info>");
                } catch (\Exception $e) {
                    $output->writeln('<error>Taxonomy import failed: ' . $e->getMessage() . '</error>');
                    continue;
                }
            }
        }
        return true;
    }

    private function installResourceTemplates(OutputInterface $output, array $manifest, string $rootDir): bool
    {
        if (!empty($manifest['resource_templates']) && is_array($manifest['resource_templates'])) {
            $serviceManager = Omeka::getApp()->getServiceManager();
            $api = $serviceManager->get('Omeka\ApiManager');

            foreach ($manifest['resource_templates'] as $template) {
                $templateLabel = $template['label'] ?? null;
                $templateFile = $template['file'] ?? null;
                if (empty($templateLabel) || empty($templateFile)) {
                    $output->writeln('<error>Resource template information incomplete in distribution.json.</error>');
                    continue;
                }
                $templateFilePath = $rootDir . '/resource_templates/' . $templateFile;
                if (!file_exists($templateFilePath)) {
                    $output->writeln("<error>Resource template file {$templateFile} not found.</error>");
                    continue;
                }
                $output->writeln("Importing resource template: {$templateLabel}...");
                $templateData = json_decode(file_get_contents($templateFilePath), true);
                $templateData = $this->prepareTemplateData($output, $templateData);
                try {
                    $api->create('resource_templates', $templateData);
                    $output->writeln("<info>Resource template '{$templateLabel}' imported successfully.</info>");
                } catch (\Exception $e) {
                    $output->writeln('<error>Resource template import failed: ' . $e->getMessage() . '</error>');
                    continue;
                }
            }
        }
        return true;
    }

    private function prepareTemplateData(OutputInterface $output, array $templateData): array
    {
        $vocabs = [];
        $serviceManager = Omeka::getApp()->getServiceManager();
        $apiMgr = $serviceManager->get('Omeka\ApiManager');
        // Create an Api plugin instance to use methods like `searchOne`.
        $api = new Api($apiMgr);

        $getVocab = function ($namespaceUri) use (&$vocabs, $api) {
            if (isset($vocabs[$namespaceUri])) {
                return $vocabs[$namespaceUri];
            }
            $vocab = $api->searchOne('vocabularies', [
                'namespace_uri' => $namespaceUri,
            ])->getContent();
            if ($vocab) {
                $vocabs[$namespaceUri] = $vocab;
                return $vocab;
            }
            return false;
        };

        // Get all custom_vocabs to map their IDs by label
        $response = $apiMgr->search('custom_vocabs');
        $customVocabs = $response->getContent();
        $customVocabMap = [];
        foreach ($customVocabs as $customVocab) {
            $customVocabMap[$customVocab->label()] = $customVocab->id();
        }

        if (isset($templateData['o:resource_class'])) {
            if ($vocab = $getVocab($templateData['o:resource_class']['vocabulary_namespace_uri'])) {
                $templateData['o:resource_class']['vocabulary_prefix'] = $vocab->prefix();
                $class = $api->searchOne('resource_classes', [
                    'vocabulary_namespace_uri' => $templateData['o:resource_class']['vocabulary_namespace_uri'],
                    'local_name' => $templateData['o:resource_class']['local_name'],
                ])->getContent();
                if ($class) {
                    $templateData['o:resource_class']['o:id'] = $class->id();
                } else {
                    $output->writeln("<comment>Warning: Resource class '{$templateData['o:resource_class']['local_name']}' not found.</comment>");
                }
            }
        }

        foreach (['o:title_property', 'o:description_property'] as $property) {
            if (isset($templateData[$property])) {
                if ($vocab = $getVocab($templateData[$property]['vocabulary_namespace_uri'])) {
                    $templateData[$property]['vocabulary_prefix'] = $vocab->prefix();
                    $prop = $api->searchOne('properties', [
                        'vocabulary_namespace_uri' => $templateData[$property]['vocabulary_namespace_uri'],
                        'local_name' => $templateData[$property]['local_name'],
                    ])->getContent();
                    if ($prop) {
                        $templateData[$property]['o:id'] = $prop->id();
                    } else {
                        $output->writeln("<comment>Warning: Property '{$templateData[$property]['local_name']}' not found for {$property}.</comment>");
                    }
                }
            }
        }

        foreach ($templateData['o:resource_template_property'] as $key => $property) {
            if ($vocab = $getVocab($property['vocabulary_namespace_uri'])) {
                $templateData['o:resource_template_property'][$key]['vocabulary_prefix'] = $vocab->prefix();
                $prop = $api->searchOne('properties', [
                    'vocabulary_namespace_uri' => $property['vocabulary_namespace_uri'],
                    'local_name' => $property['local_name'],
                ])->getContent();
                if ($prop) {
                    $templateData['o:resource_template_property'][$key]['o:property'] = ['o:id' => $prop->id()];
                    // Update the custom_vocab references to use the correct IDs
                    foreach ($templateData['o:resource_template_property'][$key]['data_types'] as $index => &$dataType) {
                        $dataTypeName = $dataType['name'];
                        $dataTypeLabel = $dataType['label'];
                        if (str_starts_with($dataTypeName, 'customvocab:')) {
                            if (isset($customVocabMap[$dataTypeLabel])) {
                                $dataType['name'] = 'customvocab:' . $customVocabMap[$dataTypeLabel];
                            } else {
                                // Remove the data type if the vocabulary is not found
                                unset($templateData['o:resource_template_property'][$key]['data_types'][$index]);
                                $output->writeln("<comment>Warning: Custom vocabulary '{$dataTypeLabel}' not found. Removed from property '{$templateData['o:resource_template_property'][$key]['label']}'.</comment>");
                            }
                        }
                    }
                    // Reindex the data types array
                    $templateData['o:resource_template_property'][$key]['data_types'] = array_values($templateData['o:resource_template_property'][$key]['data_types']);

                    // Populate o:data_type.
                    $templateData['o:resource_template_property'][$key]['o:data_type'] = [];
                    foreach ($templateData['o:resource_template_property'][$key]['data_types'] as $dt) {
                        $templateData['o:resource_template_property'][$key]['o:data_type'][] = $dt['name'];
                    }
                } else {
                    $output->writeln("<comment>Warning: Property '{$property['local_name']}' not found.</comment>");
                }
            }
        }

        return $templateData;
    }

}
