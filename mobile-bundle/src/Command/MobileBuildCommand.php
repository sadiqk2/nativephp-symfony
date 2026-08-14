<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Command;

use Native\Symfony\Mobile\Build\BuildPlan;
use Native\Symfony\Mobile\Build\CommandRunnerInterface;
use Native\Symfony\Mobile\Build\MobileBuilder;
use Native\Symfony\Mobile\Build\MobilePlatform;
use Native\Symfony\Mobile\Build\PlannedCommand;
use Native\Symfony\Mobile\Build\ProcessRunner;
use Native\Symfony\Mobile\Build\Toolchain;
use Native\Symfony\Mobile\Build\ToolchainReport;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Release build: stage the application, bake it into the native project, and produce an
 * APK/AAB or an iOS archive/.ipa.
 *
 * The pipeline is desktop's `native:build` minus the packager and plus a zip, because that
 * is what the mobile hosts consume: the whole application ships as one archive inside the
 * APK's `assets/` or the iOS bundle and is extracted on first launch. The parts worth
 * reusing from desktop are reused deliberately — the exclusion walk that never descends
 * into `node_modules`, the `.env` cleaning whose keep list beats its remove list so
 * `APP_SECRET` survives (M3-RESULTS.md finding 1), the CA bundle, and warming the
 * production cache, which matters more here than on desktop because the packaged PHP
 * cannot load opcache at all.
 *
 * **Split honestly in two.** Everything up to the artifact — staging, cleaning, the version
 * files, the zip, `bundle_meta.json` — runs and is tested in this environment, and
 * `--stage-only` stops exactly there. Everything after it is Gradle or Xcode, neither of
 * which exists here: those steps have never been executed by this command, and it says so
 * in its output rather than only in this comment. `--dry-run` prints the whole plan and
 * touches nothing.
 *
 * Signing is not handled. Gradle reads a keystore from the project's own `signingConfigs`
 * and Xcode from a provisioning profile; passing either through a command line would put a
 * password in the process table, and a signing flow that cannot be tested here would be a
 * liability sold as a feature.
 */
#[AsCommand(name: 'native:mobile:build', description: 'Stage the app and produce a release APK/AAB or iOS archive')]
final class MobileBuildCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $version,
        private readonly NativeRouteManifest $manifest,
        private readonly Toolchain $toolchain,
        private readonly CommandRunnerInterface $runner = new ProcessRunner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('platform', InputArgument::REQUIRED, 'android or ios')
            ->addOption('aab', null, InputOption::VALUE_NONE, 'Android: produce an app bundle (.aab) for Play, instead of an APK')
            ->addOption('stage-dir', null, InputOption::VALUE_REQUIRED, 'Where the application copy is assembled', 'var/nativephp/mobile')
            ->addOption('version-code', null, InputOption::VALUE_REQUIRED, 'Build number; part of the identity the hosts compare to decide whether to re-extract', '1')
            ->addOption('runtime-mode', null, InputOption::VALUE_REQUIRED, 'persistent or classic', 'persistent')
            ->addOption('force-web', null, InputOption::VALUE_NONE, 'Bake entry_mode=web, forcing the legacy WebView path')
            ->addOption('stage-only', null, InputOption::VALUE_NONE, 'Stop after the archive and metadata are written; do not invoke Gradle or Xcode')
            ->addOption('skip-composer', null, InputOption::VALUE_NONE, 'Reuse the staged vendor/ instead of reinstalling without dev dependencies')
            ->addOption('skip-cache-warmup', null, InputOption::VALUE_NONE, 'Do not warm var/cache in the staged copy')
            ->addOption('export-options', null, InputOption::VALUE_REQUIRED, 'iOS: path to an exportOptions.plist. Omit to archive only; pass "auto" for a minimal development-signing plist.')
            ->addOption('team-id', null, InputOption::VALUE_REQUIRED, 'iOS: Apple Developer team id, written into a generated exportOptions.plist')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print every step and write nothing');
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
        $stageOnly = (bool) $input->getOption('stage-only');
        $versionCode = (string) $input->getOption('version-code');
        $aab = (bool) $input->getOption('aab');

        $builder = new MobileBuilder(
            sourcePath: $this->projectDir,
            stagePath: $this->stageDir($input, $platform),
        );

        $zipPath = $platform->bundleZipPath($this->projectDir);
        $metaPath = $platform->bundleMetaPath($this->projectDir);

        $forceWeb = (bool) $input->getOption('force-web') || 'web' === getenv('NATIVEPHP_BOOT_MODE');
        $fragment = $this->manifest->bundleMetaFragment($forceWeb);

        $io->definitionList(
            ['platform' => $platform->label()],
            ['version' => MobileBuilder::versionId($this->version, $versionCode)],
            ['entry_mode' => $fragment['entry_mode']],
            ['source' => $builder->sourcePath()],
            ['stage' => $builder->stagePath()],
            ['archive' => $zipPath],
            ['manifest' => $metaPath],
            ['artifact' => MobilePlatform::Android === $platform
                ? BuildPlan::androidArtifact($this->projectDir, $aab ? 'bundle' : 'release')
                : BuildPlan::iosArchivePath($this->projectDir)],
        );

        $report = $this->toolchain->inspect($platform, $this->projectDir);
        $plan = $this->toolchainPlan($platform, $input, $builder, $dryRun);

        if ($dryRun) {
            $this->describeDryRun($io, $builder, $input, $report, $plan);

            return Command::SUCCESS;
        }

        if (!is_dir($platform->projectPath($this->projectDir))) {
            $io->error([
                sprintf('No %s project at %s.', $platform->label(), $platform->projectPath($this->projectDir)),
                'The application archive and its manifest are staged *into* that project, so there is',
                'nowhere to put them. Run native:mobile:install first.',
            ]);

            return Command::INVALID;
        }

        // -- stage ---------------------------------------------------------------
        $io->section('Staging the application');
        $copied = $builder->stageApplication(static function (int $n) use ($io): void {
            if ($io->isVerbose()) {
                $io->write("\r  {$n} files…");
            }
        });
        $io->text(sprintf('Copied %d files to %s', $copied, $builder->stagePath()));

        foreach ([
            'Installing production dependencies' => $input->getOption('skip-composer') ? null : $builder->productionDependenciesCommand(),
            'Warming the production cache' => $input->getOption('skip-cache-warmup') ? null : $builder->cacheWarmupCommand(),
        ] as $label => $command) {
            if (null === $command) {
                continue;
            }

            $io->section($label);
            $io->writeln('$ '.$command->display());

            $exit = $this->runner->run($command, static fn (string $type, string $chunk) => $io->isVerbose() ? $io->write($chunk) : null);

            if (0 !== $exit) {
                $io->error(sprintf('"%s" failed with exit code %d. Re-run with -v for its output.', $command->display(), $exit));

                return Command::FAILURE;
            }
        }

        $io->section('Cleaning the environment file and stamping the version');
        // Both hosts compare a composite "{version}b{code}" against what they already
        // extracted, and iOS reads that pair out of the staged .env in preference to
        // anything else — so the .env values and the .version file have to agree, or the
        // app re-extracts the whole bundle on every cold boot.
        $builder->cleanEnvironmentFile($this->version, $versionCode);
        $io->text(' • .version → '.$builder->writeVersionFile($this->version, $versionCode));

        if (!$builder->installCertificateAuthority()) {
            $io->warning([
                'No cacert.pem found (expected in vendor/nativephp/php-bin).',
                'Outbound TLS from the packaged app will fail. Install it with: composer require nativephp/php-bin',
            ]);
        }

        // -- archive and manifest -------------------------------------------------
        $io->section('Creating the application archive');

        try {
            $entries = $builder->createBundleArchive($zipPath);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->text(sprintf('%d entries, %s → %s', $entries, $this->humanSize($zipPath), $zipPath));

        if (null !== $versionFile = $platform->bundledVersionPath($this->projectDir)) {
            file_put_contents($versionFile, MobileBuilder::versionId($this->version, $versionCode));
            $io->text(' • bundled.version → '.$versionFile);
        }

        try {
            $builder->writeBundleMeta($metaPath, $fragment, [
                'version_code' => $versionCode,
                'runtime_mode' => (string) $input->getOption('runtime-mode'),
            ]);
        } catch (\RuntimeException $e) {
            $io->error([sprintf('Refusing to write %s', $metaPath), $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->text(sprintf(' • bundle_meta.json → %s (%d native routes, entry_mode=%s)', $metaPath, \count($fragment[NativeRouteManifest::BAKED_ROUTES_KEY]), $fragment['entry_mode']));

        if ($stageOnly) {
            $io->success([
                'Staged. The native project now carries the application archive and its manifest.',
                'Nothing was compiled: --stage-only stops before Gradle and Xcode, which is also',
                'the boundary of what this bundle can verify.',
            ]);

            return Command::SUCCESS;
        }

        // -- compile --------------------------------------------------------------
        $io->section(sprintf('%s toolchain', $platform->label()));

        foreach ($report->lines() as $line) {
            $io->writeln($line);
        }

        if (!$report->isSatisfied()) {
            $io->error(array_merge(
                [
                    sprintf('The %s toolchain is incomplete, so nothing was compiled.', $platform->label()),
                    'The application archive and manifest above ARE written, so a machine with the',
                    'toolchain can finish the build from this project as it stands.',
                ],
                array_map(static fn ($c): string => $c->name.' — '.$c->hint, $report->missing()),
            ));

            return Command::FAILURE;
        }

        if (MobilePlatform::Android === $platform && null !== $sdk = $this->toolchain->androidSdkPath()) {
            // Gradle reads sdk.dir from local.properties; ANDROID_HOME alone is not enough
            // for every plugin in the project.
            $io->text(' • '.$builder->writeAndroidLocalProperties($this->projectDir, $sdk));
        }

        return $this->executePlan($plan, $report, $io, $platform, $input, $aab);
    }

    /**
     * @return list<PlannedCommand>
     */
    private function toolchainPlan(MobilePlatform $platform, InputInterface $input, MobileBuilder $builder, bool $dryRun): array
    {
        if (MobilePlatform::Android === $platform) {
            return BuildPlan::androidBuild($this->projectDir, (bool) $input->getOption('aab'));
        }

        $exportOptions = $input->getOption('export-options');

        if ('auto' === $exportOptions) {
            $path = MobilePlatform::Ios->projectPath($this->projectDir).'/build/exportOptions.plist';

            // Not written during a dry run — the whole promise of --dry-run is that the
            // filesystem is untouched.
            if (!$dryRun) {
                $teamId = $input->getOption('team-id');
                $builder->writeExportOptions($path, teamId: \is_string($teamId) && '' !== $teamId ? $teamId : null);
            }

            $exportOptions = $path;
        }

        return BuildPlan::iosBuild($this->projectDir, \is_string($exportOptions) && '' !== $exportOptions ? $exportOptions : null);
    }

    /**
     * @param list<PlannedCommand> $plan
     */
    private function describeDryRun(SymfonyStyle $io, MobileBuilder $builder, InputInterface $input, ToolchainReport $report, array $plan): void
    {
        $io->section('Staging (not performed)');
        $io->listing([
            'exclude: '.implode(' ', $builder->excludePatterns()),
            'env kept whatever else says: '.implode(' ', MobileBuilder::DEFAULT_ENV_KEEP).' — APP_SECRET must survive or the packaged app will not boot',
            'env stripped: '.implode(' ', MobileBuilder::DEFAULT_ENV_REMOVE),
            'directories forced to exist: '.implode(' ', MobileBuilder::DEFAULT_KEEP),
            'composer install --no-dev: '.($input->getOption('skip-composer') ? 'skipped' : 'yes'),
            'cache:warmup --env=prod: '.($input->getOption('skip-cache-warmup') ? 'skipped' : 'yes'),
        ]);

        $io->section(sprintf('%s toolchain', $report->platform->label()));

        foreach ($report->lines() as $line) {
            $io->writeln($line);
        }

        $io->section('Compile (not performed)');

        foreach ($plan as $i => $command) {
            $io->writeln(sprintf(' %d. %s', $i + 1, $command->purpose));
            $io->writeln(sprintf('    $ %s', $command->display()));
            $io->writeln(sprintf('    (in %s)', $command->cwd));
        }

        $io->comment('Dry run: nothing was staged, written or compiled.');
    }

    /**
     * @param list<PlannedCommand> $plan
     */
    private function executePlan(array $plan, ToolchainReport $report, SymfonyStyle $io, MobilePlatform $platform, InputInterface $input, bool $aab): int
    {
        $io->warning([
            'Gradle and Xcode have never been invoked by this command in the environment it was',
            'written in — there is no Android SDK and no Xcode here. The invocations are',
            'transcribed from upstream and the plan is tested, but nothing below is verified.',
        ]);

        foreach ($plan as $command) {
            $resolved = $this->resolveProgram($command, $report);

            $io->section($command->purpose);
            $io->writeln('$ '.$resolved->display());

            $exit = $this->runner->run($resolved, static function (string $type, string $chunk) use ($io): void {
                $io->write($chunk);
            });

            if (0 !== $exit && !$command->tolerateFailure) {
                $io->error(sprintf('"%s" failed with exit code %d.', $resolved->display(), $exit));

                return Command::FAILURE;
            }
        }

        $artifact = MobilePlatform::Android === $platform
            ? BuildPlan::androidArtifact($this->projectDir, $aab ? 'bundle' : 'release')
            : BuildPlan::iosArchivePath($this->projectDir);

        if (!file_exists($artifact)) {
            // Every command reported success and the artifact is absent: either the path
            // moved upstream or the build produced something else. Say so instead of
            // printing a path that is not there.
            $io->error([
                'Every command reported success, but the expected artifact is missing:',
                $artifact,
                'The path is transcribed from upstream and may have moved. Check the toolchain\'s own output above.',
            ]);

            return Command::FAILURE;
        }

        $io->success(sprintf('Build complete: %s (%s)', $artifact, $this->humanSize($artifact)));

        return Command::SUCCESS;
    }

    private function resolveProgram(PlannedCommand $command, ToolchainReport $report): PlannedCommand
    {
        if ('./gradlew' === $command->program()) {
            $wrapper = $report->pathFor('gradlew');

            if (null === $wrapper) {
                return $command;
            }

            if (!is_executable($wrapper)) {
                @chmod($wrapper, 0o755);
            }

            return $command->withProgram($wrapper);
        }

        $found = $report->pathFor($command->program());

        return null === $found ? $command : $command->withProgram($found);
    }

    private function stageDir(InputInterface $input, MobilePlatform $platform): string
    {
        $dir = (string) $input->getOption('stage-dir');

        // Per-platform, because the two archives are not identical: the staged .env carries
        // platform-independent values today, but a shared directory would make any future
        // divergence a silent cross-contamination between builds.
        return (str_starts_with($dir, '/') ? $dir : $this->projectDir.'/'.$dir).'/'.$platform->value;
    }

    private function humanSize(string $path): string
    {
        $bytes = is_file($path) ? (int) filesize($path) : 0;

        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
