<?php

declare(strict_types=1);

namespace App\Native\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Requirement 3: print PHP ini overrides as JSON on stdout.
 *
 * The runtime merges these under its own defaults (memory_limit=512M,
 * curl.cainfo, openssl.cafile) and passes the result as -d flags to every PHP
 * process it spawns. An empty object is a valid answer.
 */
#[AsCommand(name: 'native:php-ini', description: 'Print PHP ini overrides as JSON')]
final class NativePhpIniCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->write(json_encode([
            'memory_limit' => '512M',
        ], \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
