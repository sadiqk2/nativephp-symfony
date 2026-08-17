<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The runtime spawns a scheduler tick every 60 seconds, aligned to the minute
 * boundary, and does not check that the command exists first. Upstream hardcodes
 * `schedule:run`; RuntimePatcher retargets it here so that:
 *
 *  - an app with no scheduler does not log a console error every minute, and
 *  - an app that *does* use symfony/scheduler keeps its own schedule:run
 *    semantics instead of having the runtime drive it unasked.
 *
 * Override the service to hook up symfony/scheduler, a Messenger dispatch, or
 * anything else that wants a once-a-minute tick.
 */
#[AsCommand(name: 'native:schedule-tick', description: 'Runtime scheduler tick (no-op by default)')]
class ScheduleTickCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
