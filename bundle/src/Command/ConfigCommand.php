<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Contract requirement 2.
 *
 * The runtime runs this as the very first thing it does — before its API server
 * exists — captures stdout with execFile and JSON.parse()s the result. Two rules
 * follow and both are absolute:
 *
 *  - Nothing but JSON on stdout. No banner, no deprecation notice, no dump().
 *  - Never assume NATIVEPHP_API_URL or NATIVEPHP_SECRET are set. They are not,
 *    at this point in the boot.
 *
 * If this command fails, the runtime logs the error and carries on with an empty
 * config — no app id, no deep links, no updater, and no visible symptom. That
 * silent degradation is why the output here is kept trivially simple.
 *
 * Only five keys are actually read by the runtime; the rest of the config tree
 * is consumed by build tooling.
 */
#[AsCommand(name: 'native:config', description: 'Print the NativePHP startup configuration as JSON')]
final class ConfigCommand extends Command
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // OUTPUT_RAW so no formatter can touch the JSON, and no trailing newline
        // — JSON.parse() tolerates one, but there is nothing to gain by adding it.
        $output->write(
            json_encode($this->config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            false,
            OutputInterface::OUTPUT_RAW,
        );

        return Command::SUCCESS;
    }
}
