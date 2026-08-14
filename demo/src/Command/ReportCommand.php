<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A pretend long job, so the child-process screen has something of the app's own to
 * run rather than only a PHP one-liner.
 *
 * Note the explicit flush: the child's stdout is a pipe, not a terminal, so PHP
 * buffers it and the runtime would deliver one MessageReceived event at the end
 * instead of five as they happen. Every desktop app that streams progress hits this.
 */
#[AsCommand(name: 'app:report', description: 'Emit a few lines of progress, slowly')]
final class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('rows', null, InputOption::VALUE_REQUIRED, 'How many rows to emit', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = max(1, (int) $input->getOption('rows'));

        for ($row = 1; $row <= $rows; ++$row) {
            $output->writeln(sprintf('report: row %d of %d', $row, $rows));
            flush();
            usleep(500_000);
        }

        $output->writeln('report: complete');

        return Command::SUCCESS;
    }
}
