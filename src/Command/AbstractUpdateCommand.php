<?php

namespace App\Command;

use App\Distribution\Inspector;
use App\Omeka;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Shared plumbing for the update commands.
 *
 * This holds only the console-level chores every update command repeats - reading config.json,
 * checking the admin credentials, asking for confirmation and formatting a version pair. The actual
 * update logic lives in App\Distribution so that it can be shared without going through Symfony's
 * command lifecycle.
 */
abstract class AbstractUpdateCommand extends Command
{
    /**
     * Get the repository root.
     */
    protected function getRootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Read the admin credentials out of config/config.json.
     *
     * @return array|null The credentials with "email" and "password", or null when they cannot be
     *   read. The reason has already been written to the output.
     */
    protected function readCredentials(OutputInterface $output): ?array
    {
        $configPath = $this->getRootDir() . '/config/config.json';
        if (!file_exists($configPath)) {
            $output->writeln('<error>config.json not found.</error>');
            return null;
        }
        $config = json_decode(file_get_contents($configPath), true);
        if (!isset($config['admin']['email']) || !isset($config['admin']['password'])) {
            $output->writeln('<error>Admin credentials not found in config.json.</error>');
            return null;
        }
        return $config['admin'];
    }

    /**
     * Check the admin credentials against the database.
     *
     * Done before any download so that bad credentials are reported up front rather than after a
     * large download that the database step would then refuse to finish. The update itself is
     * authorised by Omeka once the new code is in place.
     */
    protected function verifyCredentials(OutputInterface $output, Inspector $inspector, array $credentials): bool
    {
        try {
            if (!$inspector->verifyCredentials($credentials['email'], $credentials['password'])) {
                $output->writeln('<error>The admin credentials in config.json are not valid for this installation.</error>');
                return false;
            }
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return false;
        }
        return true;
    }

    /**
     * Authenticate with Omeka, for the steps that need an identity.
     *
     * Must not be called while the core database is behind the core code. Omeka's
     * AuthenticationServiceFactory substitutes a stub adapter that rejects every credential when it
     * boots with migrations pending, and the service manager caches that stub for the life of the
     * process - so authentication is impossible in that state no matter what is passed, and stays
     * impossible after the migrations unless the application is re-initialised. Core migrations
     * themselves need no identity, so the caller must run them first.
     *
     * The credentials have already been checked against the user table before the download, so a
     * failure here is almost always that ordering rather than a bad password - say so instead of
     * blaming the credentials.
     */
    protected function authenticateWithOmeka(OutputInterface $output, array $credentials): bool
    {
        if (Omeka::authenticate($credentials['email'], $credentials['password'])) {
            return true;
        }

        /** @var \Omeka\Mvc\Status $status */
        $status = Omeka::getApp()->getServiceManager()->get('Omeka\Status');
        // The exact condition under which Omeka installs the stub adapter.
        if (!$status->isInstalled() || ($status->needsVersionUpdate() && $status->needsMigration())) {
            $output->writeln(sprintf(
                '<error>Omeka S cannot authenticate because its database (%s) is behind the core code on disk (%s). Run "php console update:core" first, then this command again.</error>',
                $status->getInstalledVersion(),
                $status->getVersion()
            ));
            return false;
        }

        $output->writeln('<error>Could not authenticate with Omeka S using the credentials in config.json.</error>');
        return false;
    }

    /**
     * Ask the user to confirm an update.
     *
     * @return bool False when the user declined, so the caller should stop without doing any work.
     */
    protected function confirm(InputInterface $input, OutputInterface $output, bool $autoConfirm): bool
    {
        if ($autoConfirm) {
            return true;
        }
        $qHelper = new QuestionHelper();
        return (bool) $qHelper->ask($input, $output, new ConfirmationQuestion('Would you like to continue? (y|n)', false));
    }

    /**
     * Format a from/to version pair for display.
     *
     * A null "from" means the component is not present yet, which reads much better spelled out than
     * as the empty string.
     */
    protected function formatVersions(?string $from, string $to): string
    {
        return ($from ?? 'not installed') . ' => ' . $to;
    }
}
