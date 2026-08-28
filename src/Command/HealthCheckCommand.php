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
        $this->addOption('media-samples', null, InputOption::VALUE_REQUIRED, 'How many media files to sample.', (string) CheckerFactory::DEFAULT_MEDIA_SAMPLES);
        $this->addOption('slow-ms', null, InputOption::VALUE_REQUIRED, 'Response time in milliseconds above which a request warns.', (string) CheckerFactory::DEFAULT_SLOW_MS);
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP request timeout in seconds.', (string) CheckerFactory::DEFAULT_TIMEOUT_SECONDS);
        $this->addOption('insecure', null, InputOption::VALUE_NONE, 'Skip TLS certificate verification.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootDir = dirname(__DIR__, 2);
        $strict = (bool) $input->getOption('strict');
        $json = (bool) $input->getOption('json');

        $numbers = [];
        foreach (['timeout', 'slow-ms', 'media-samples'] as $option) {
            $value = $this->parseNonNegativeInt($input->getOption($option));
            if ($value === null) {
                $output->writeln(sprintf(
                    '<error>--%s must be a non-negative whole number, got "%s".</error>',
                    $option,
                    (string) $input->getOption($option)
                ));
                return Command::INVALID;
            }
            $numbers[$option] = $value;
        }

        $factory = new CheckerFactory(
            $rootDir,
            $this->resolveBaseUrl($rootDir, $input->getOption('url')),
            $numbers['timeout'],
            (bool) $input->getOption('insecure'),
            $numbers['slow-ms'],
            $numbers['media-samples'],
        );

        $report = $factory->createRunner()->run();

        if ($json) {
            (new JsonRenderer())->render($report, $factory->baseUrl(), $strict, $output);
        } else {
            (new ConsoleRenderer())->render($report, $output, $strict);
        }

        return $report->exitCode($strict);
    }

    /**
     * Work out the base URL: the option wins, then config.json, then nothing.
     *
     * Nothing is not an error. Omeka S stores no base URL of its own, so without one the HTTP checks
     * simply skip and the database and filesystem checks still run.
     */
    private function resolveBaseUrl(string $rootDir, mixed $option): ?string
    {
        if (is_string($option) && $option !== '') {
            return rtrim($option, '/');
        }
        return CheckerFactory::baseUrlFromConfig($rootDir);
    }

    /**
     * Read a numeric option, or null when it is not a non-negative whole number.
     *
     * Casting with (int) instead would turn a typo into a plausible-looking zero, and every one of
     * these options means something dangerous at zero: Guzzle reads timeout 0 as "no timeout", so
     * --timeout=abc would silently disable the very thing it configures, and --media-samples=abc
     * becomes LIMIT 0, which returns no rows and makes the media check state confidently that the
     * instance has no stored files.
     */
    private function parseNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^\d+$/', trim($value))) {
            return null;
        }
        return (int) trim($value);
    }
}
