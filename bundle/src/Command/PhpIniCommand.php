<?php

declare(strict_types=1);

namespace Native\Symfony\Command;

use Native\Symfony\Contract\ProvidesPhpIni;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Contract requirement 3. Same stdout discipline as ConfigCommand.
 *
 * Whatever this prints is merged over the runtime's defaults (memory_limit=512M,
 * curl.cainfo, openssl.cafile) and passed as -d flags to every PHP process the
 * runtime spawns, including child processes and the scheduler.
 */
#[AsCommand(name: 'native:php-ini', description: 'Print PHP ini overrides as JSON')]
final class PhpIniCommand extends Command
{
    /** @param array<string, scalar> $configured */
    public function __construct(
        private readonly array $configured = [],
        private readonly ?ProvidesPhpIni $provider = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ini = [...$this->configured, ...($this->provider?->phpIni() ?? [])];

        $output->write(json_encode($ini, \JSON_THROW_ON_ERROR), false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
