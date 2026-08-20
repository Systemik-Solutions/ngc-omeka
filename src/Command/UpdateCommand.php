<?php

namespace App\Command;

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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $autoConfirm = $input->getOption('yes');
        $application = $this->getApplication();

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

        return $dbCommand->run($dbInput, $output);
    }
}
