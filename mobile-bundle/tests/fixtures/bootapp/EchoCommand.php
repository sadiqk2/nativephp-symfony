<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Proves the console shim passes the host's argv through and returns the exit code. */
#[AsCommand(name: 'app:echo', description: 'Echo the arguments back')]
final class EchoCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('word', InputArgument::OPTIONAL, '', 'nothing')
            ->addArgument('code', InputArgument::OPTIONAL, '', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('echo:'.$input->getArgument('word'));
        $output->writeln('env:'.($_SERVER['APP_ENV'] ?? '?').' debug:'.($_SERVER['APP_DEBUG'] ?? '?'));

        return (int) $input->getArgument('code');
    }
}
