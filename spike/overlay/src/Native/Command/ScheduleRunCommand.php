<?php

declare(strict_types=1);

namespace App\Native\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The runtime spawns `<cli> schedule:run` every 60 seconds, aligned to the
 * minute boundary (boot step 10). It is hardcoded, not optional, and a missing
 * command just produces silent stderr noise — which is exactly why lifecycle
 * commands belong in the manifest (see ANALYSIS.md section 6, item 2).
 *
 * A no-op stub keeps the spike's output clean. A real bundle would map this to
 * Symfony Scheduler.
 */
#[AsCommand(name: 'schedule:run', description: 'No-op stub for the runtime scheduler tick')]
final class ScheduleRunCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
