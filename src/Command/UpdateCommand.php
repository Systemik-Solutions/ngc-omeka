<?php

namespace App\Command;

use App\Database\Connection;
use App\Distribution\Inspector;
use App\Health\CheckerFactory;
use App\Health\Probe\DbProbe;
use App\Health\Renderer\ConsoleRenderer;
use App\Health\Snapshot;
use App\Omeka;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the code update and the database update in a single command.
 *
 * The two steps have to happen in this order within one process, and the ordering is load-bearing:
 * "update:code" deliberately audits the installation without bootstrapping Omeka S, because PHP
 * cannot reload a class once it is declared. Bootstrapping before the download would leave the
 * process running the replaced core and module code, so "update:db" would then invoke the old
 * Module::upgrade() methods and record the old core version. Bootstrapping only after the download
 * means the new code is loaded from the start.
 */
class UpdateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('update');
        $this->setDescription('Updates the NGC Omeka S distribution code and applies the pending database updates.');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatic yes to prompts; assume "yes" as answer to all prompts and run non-interactively.');
        $this->addOption('health-check', null, InputOption::VALUE_NONE, 'Run the health checks after the update and report the result.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $autoConfirm = $input->getOption('yes');
        $healthCheck = (bool) $input->getOption('health-check');
        $application = $this->getApplication();

        // Captured before the download, through raw PDO and the filesystem only. This is the same
        // rule the lock below enforces: nothing here may load an Omeka class, because PHP cannot
        // replace a class once it is declared and the rest of the process would then be running
        // the old code.
        $before = null;
        if ($healthCheck) {
            $before = $this->captureSnapshot();
        }

        /**
         * @var \App\Command\UpdateCodeCommand $codeCommand
         */
        $codeCommand = $application->find('update:code');
        $codeCommand->setChained(true);

        $codeArgs = ['command' => 'update:code'];
        if ($autoConfirm) {
            $codeArgs['--yes'] = true;
        }
        $codeInput = new ArrayInput($codeArgs);
        $codeInput->setInteractive($input->isInteractive());

        // Guard the ordering described above: any attempt to bootstrap Omeka S during the download now
        // fails with an explanation rather than silently updating against stale code.
        Omeka::lockBootstrap();
        try {
            $exitCode = $codeCommand->run($codeInput, $output);
        } finally {
            Omeka::unlockBootstrap();
        }

        if ($exitCode !== Command::SUCCESS) {
            if ($healthCheck) {
                $output->writeln('');
                $output->writeln('<comment>The update did not finish, so the health checks were not run.</comment>');
                $output->writeln('<comment>Run "php console health:check" for a damage assessment.</comment>');
            }
            return $exitCode;
        }

        // The user was asked to confirm the whole update before the download, so a decline stops here
        // rather than falling through to the database changes.
        if ($codeCommand->wasCancelled()) {
            return Command::SUCCESS;
        }

        $output->writeln('');

        // Omeka S is bootstrapped for the first time inside this command, picking up the code that was
        // just downloaded. The confirmation above covered this step too.
        $dbCommand = $application->find('update:db');
        $dbInput = new ArrayInput(['command' => 'update:db', '--yes' => true]);
        $dbInput->setInteractive(false);

        $dbExitCode = $dbCommand->run($dbInput, $output);

        if (!$healthCheck) {
            return $dbExitCode;
        }

        if ($dbExitCode !== Command::SUCCESS) {
            $output->writeln('');
            $output->writeln('<comment>The update did not finish, so the health checks were not run.</comment>');
            $output->writeln('<comment>Run "php console health:check" for a damage assessment.</comment>');
            return $dbExitCode;
        }

        return $this->runHealthCheck($output, $before);
    }

    /**
     * Take a state snapshot, or null when there is no database to read.
     *
     * Uses raw PDO and the filesystem only, so it is safe to call before the code download.
     */
    private function captureSnapshot(): ?Snapshot
    {
        $rootDir = dirname(__DIR__, 2);
        $connection = new Connection($rootDir);
        if (!$connection->isConfigured()) {
            return null;
        }
        try {
            return Snapshot::capture(new DbProbe($connection), new Inspector($rootDir, $connection));
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Run the health checks and report, after the update has been applied.
     *
     * A non-zero return here means the update was applied and then failed verification. The output
     * has to say so: read as "nothing happened", it would send someone looking for a rollback that
     * never occurred.
     */
    private function runHealthCheck(OutputInterface $output, ?Snapshot $before): int
    {
        $rootDir = dirname(__DIR__, 2);

        $output->writeln('');
        $output->writeln('<info>Running the health checks.</info>');
        $output->writeln('');

        $configPath = $rootDir . '/config/config.json';
        $baseUrl = null;
        if (is_readable($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true);
            if (is_array($config) && isset($config['url']) && is_string($config['url']) && $config['url'] !== '') {
                $baseUrl = rtrim($config['url'], '/');
            }
        }

        $factory = new CheckerFactory($rootDir, $baseUrl, 30, false, 5000, 5);
        $report = $factory->createRunner()->run();

        $after = $this->captureSnapshot();
        if ($before !== null && $after !== null) {
            $report->add(...$after->diff($before));
        }

        (new ConsoleRenderer())->render($report, $output);

        if ($report->hasFailures()) {
            $output->writeln('');
            $output->writeln('<error>The update was applied, but the instance failed its health checks.</error>');
            $output->writeln('<error>Nothing has been rolled back. Address the failures above.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
