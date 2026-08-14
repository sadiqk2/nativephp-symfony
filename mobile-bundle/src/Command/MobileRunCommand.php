<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Command;

use Native\Symfony\Mobile\Build\BuildPlan;
use Native\Symfony\Mobile\Build\CommandRunnerInterface;
use Native\Symfony\Mobile\Build\MobilePlatform;
use Native\Symfony\Mobile\Build\PlannedCommand;
use Native\Symfony\Mobile\Build\ProcessRunner;
use Native\Symfony\Mobile\Build\Toolchain;
use Native\Symfony\Mobile\Build\ToolchainReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Debug build, install and launch on a device or simulator.
 *
 * **This command has never been run to completion.** There is no Android SDK and no Xcode
 * in this environment, so `./gradlew assembleDebug`, `adb install`, `xcodebuild` and
 * `simctl` have never been executed by it — not once. What is built and tested here is
 * everything up to the toolchain boundary: the plan (exact argv, order, artifact paths),
 * the toolchain detection and its remediation advice, and the `--dry-run` path, which
 * executes nothing by construction. Past that boundary the command reports what it is
 * about to do and what the toolchain answered, and claims nothing else.
 *
 * That is why `--dry-run` is not a convenience here but the primary interface: it is the
 * part that can be trusted, and it prints commands you can paste into a terminal on a
 * machine that does have the toolchain.
 *
 * Assumes the app archive is already staged — `native:mobile:build --stage-only` — or that
 * the project's existing bundle is current. This command deliberately does not stage: a
 * run that silently re-zipped a few hundred megabytes would be the wrong default for the
 * "change one file, look at the screen" loop.
 */
#[AsCommand(name: 'native:mobile:run', description: 'Build, install and launch a debug build (needs a real toolchain)')]
final class MobileRunCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $appId,
        private readonly Toolchain $toolchain,
        private readonly CommandRunnerInterface $runner = new ProcessRunner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('platform', InputArgument::REQUIRED, 'android or ios')
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'Android device serial from `adb devices`; omit only when exactly one device is attached')
            ->addOption('udid', null, InputOption::VALUE_REQUIRED, 'iOS simulator UDID from `xcrun simctl list devices`')
            ->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'Override the configured application id')
            ->addOption('list-devices', null, InputOption::VALUE_NONE, 'Print the command that lists attached devices, and run it if the toolchain is present')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan and the toolchain report; execute nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $platform = MobilePlatform::tryFromName((string) $input->getArgument('platform'));

        if (null === $platform) {
            $io->error(sprintf('Unknown platform "%s". Choose one of: %s.', (string) $input->getArgument('platform'), implode(', ', MobilePlatform::names())));

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $appId = (string) ($input->getOption('app-id') ?? '') ?: $this->appId;

        $report = $this->toolchain->inspect($platform, $this->projectDir);

        $io->section(sprintf('%s toolchain', $platform->label()));

        foreach ($report->lines() as $line) {
            $io->writeln($line);
        }

        if ($input->getOption('list-devices')) {
            $plan = [MobilePlatform::Android === $platform
                ? BuildPlan::androidDevices($this->projectDir)
                : BuildPlan::iosDevices($this->projectDir)];
        } else {
            $plan = $this->plan($platform, $appId, $input, $io, $dryRun);

            if (null === $plan) {
                return Command::INVALID;
            }
        }

        $io->section('Plan');

        foreach ($plan as $i => $command) {
            $io->writeln(sprintf(' %d. %s', $i + 1, $command->purpose));
            $io->writeln(sprintf('    $ %s', $command->display()));
            $io->writeln(sprintf('    (in %s)', $command->cwd));
        }

        if ($dryRun) {
            $io->comment(sprintf(
                'Dry run: nothing was executed. %s',
                $report->isSatisfied()
                    ? 'The toolchain looks present, so dropping --dry-run would attempt the above.'
                    : 'The toolchain is incomplete, so a real run would stop before the first command.',
            ));

            return Command::SUCCESS;
        }

        if (!$report->isSatisfied()) {
            $io->error(array_merge(
                [sprintf('Cannot run on %s: the toolchain is incomplete. Nothing was executed.', $platform->label())],
                array_map(static fn ($c): string => $c->name.' — '.$c->hint, $report->missing()),
            ));

            return Command::FAILURE;
        }

        return $this->executePlan($plan, $report, $io);
    }

    /**
     * @return list<PlannedCommand>|null Null when the input is not usable
     */
    private function plan(MobilePlatform $platform, string $appId, InputInterface $input, SymfonyStyle $io, bool $dryRun): ?array
    {
        if (MobilePlatform::Android === $platform) {
            $device = $input->getOption('device');

            return BuildPlan::androidRun($this->projectDir, $appId, \is_string($device) && '' !== $device ? $device : null);
        }

        $udid = $input->getOption('udid');

        if (!\is_string($udid) || '' === $udid) {
            if (!$dryRun) {
                $io->error([
                    'A simulator --udid is required.',
                    'List them with: bin/console native:mobile:run ios --list-devices',
                    'or directly: xcrun simctl list devices available',
                    'xcodebuild needs a concrete -destination id=…; unlike simctl it does not accept "booted".',
                ]);

                return null;
            }

            // A placeholder that could not be mistaken for a real UDID, so dry-run output is
            // still complete and still obviously a template.
            $udid = 'REPLACE-WITH-SIMULATOR-UDID';
        }

        return BuildPlan::iosRun($this->projectDir, $appId, $udid);
    }

    /**
     * @param list<PlannedCommand> $plan
     */
    private function executePlan(array $plan, ToolchainReport $report, SymfonyStyle $io): int
    {
        $io->warning([
            'What follows has never been exercised in the environment this bundle was written in:',
            'no Android SDK, no Xcode. The commands are transcribed from upstream and the plan is',
            'tested, but their behaviour on a real toolchain is unverified. Treat a failure below',
            'as a bug report worth filing rather than as your mistake.',
        ]);

        foreach ($plan as $command) {
            $resolved = $this->resolveProgram($command, $report);

            $io->section($command->purpose);
            $io->writeln('$ '.$resolved->display());

            $exit = $this->runner->run($resolved, static function (string $type, string $chunk) use ($io): void {
                $io->write($chunk);
            });

            if (0 === $exit) {
                continue;
            }

            if ($command->tolerateFailure) {
                $io->comment(sprintf('Exit %d, which this step tolerates: %s', $exit, $command->purpose));

                continue;
            }

            $io->error(sprintf('"%s" failed with exit code %d. Stopping; nothing after this step ran.', $resolved->display(), $exit));

            return Command::FAILURE;
        }

        $io->success('Every planned command reported success. The app should now be running on the target.');

        return Command::SUCCESS;
    }

    /**
     * Replace a bare program name with the absolute path detection found.
     *
     * Necessary because the tools are routinely absent from PATH even on a working machine
     * — Android Studio installs `adb` under the SDK and adds nothing to a shell profile —
     * and because a found-then-not-found tool is a confusing failure to debug.
     */
    private function resolveProgram(PlannedCommand $command, ToolchainReport $report): PlannedCommand
    {
        $program = $command->program();

        if ('./gradlew' === $program) {
            $wrapper = $report->pathFor('gradlew');

            if (null === $wrapper) {
                return $command;
            }

            // A wrapper copied without its permission bit is a common consequence of
            // shipping the project through a zip; upstream repairs it the same way.
            if (!is_executable($wrapper)) {
                @chmod($wrapper, 0o755);
            }

            return $command->withProgram($wrapper);
        }

        $found = $report->pathFor($program);

        return null === $found ? $command : $command->withProgram($found);
    }
}
