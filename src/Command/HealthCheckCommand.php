<?php

namespace App\Command;

use App\Health\CheckerFactory;
use App\Health\Renderer\ConsoleRenderer;
use App\Health\Renderer\JsonRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks the health of the installed Omeka S instance.
 *
 * Deliberately does not extend AbstractUpdateCommand: it updates nothing, needs no admin
 * credentials, and has to work when config/config.json is absent entirely. The only thing it reads
 * from that file is the optional base URL.
 */
class HealthCheckCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('health:check');
        $this->setDescription('Checks the health of the installed Omeka S instance.');
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'Base URL of the instance. Overrides the "url" key in config.json.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the report as JSON instead of text.');
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as failures for the exit code.');
        $this->addOption('media-samples', null, InputOption::VALUE_REQUIRED, 'How many media files to sample.', '5');
        $this->addOption('slow-ms', null, InputOption::VALUE_REQUIRED, 'Response time in milliseconds above which a request warns.', '5000');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP request timeout in seconds.', '30');
        $this->addOption('insecure', null, InputOption::VALUE_NONE, 'Skip TLS certificate verification.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootDir = dirname(__DIR__, 2);
        $strict = (bool) $input->getOption('strict');
        $json = (bool) $input->getOption('json');

        $factory = new CheckerFactory(
            $rootDir,
            $this->resolveBaseUrl($rootDir, $input->getOption('url')),
            (int) $input->getOption('timeout'),
            (bool) $input->getOption('insecure'),
            (int) $input->getOption('slow-ms'),
            (int) $input->getOption('media-samples'),
        );

        $report = $factory->createRunner()->run();

        if ($json) {
            (new JsonRenderer())->render($report, $factory->baseUrl(), $strict, $output);
        } else {
            (new ConsoleRenderer())->render($report, $output);
        }

        return $report->exitCode($strict);
    }

    /**
     * Work out the base URL: the option wins, then config.json, then nothing.
     *
     * Nothing is not an error. Omeka S stores no base URL of its own, so without one the HTTP checks
     * simply skip and the database and filesystem checks still run.
     */
    private function resolveBaseUrl(string $rootDir, ?string $option): ?string
    {
        if (is_string($option) && $option !== '') {
            return rtrim($option, '/');
        }

        $configPath = $rootDir . '/config/config.json';
        if (!is_readable($configPath)) {
            return null;
        }
        $config = json_decode((string) file_get_contents($configPath), true);
        if (!is_array($config) || !isset($config['url']) || !is_string($config['url']) || $config['url'] === '') {
            return null;
        }
        return rtrim($config['url'], '/');
    }
}
