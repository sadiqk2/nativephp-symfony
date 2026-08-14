<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Command;

use Native\Symfony\Mobile\Build\BundleMetaWriter;
use Native\Symfony\Mobile\Build\MobilePlatform;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Write the native-route manifest in both the shapes the device reads.
 *
 * Which screens boot into the native runloop is decided *before PHP runs*, from two files:
 * the `native_routes` list baked into `bundle_meta.json` beside the app archive, and a
 * `routes` list dumped at `storage/framework/native_routes.json` on every PHP boot. Get
 * either wrong and a screen is stranded — booted natively with nothing to serve it, or
 * served by PHP that the host never asks.
 *
 * `native:mobile:build` calls the same code, so this command exists for the two cases a
 * build does not cover: refreshing the route list into an already-built project without a
 * rebuild, and inspecting what the device would actually boot with (`--dry-run`).
 *
 * Three §7b behaviours are enforced here rather than documented and hoped for:
 *
 *  - **The two files use different key names** — `native_routes` baked, `routes` at runtime.
 *    Both come from {@see NativeRouteManifest}, so neither can drift.
 *  - **`native_routes` is always baked.** Android can boot native from the runtime dump
 *    alone; iOS cannot, so a dump without a bake is a silent fallback to the WebView. When
 *    no native project is installed to bake into, this command fails rather than reporting
 *    a half-written manifest as success.
 *  - **The dump's directory may not exist.** Upstream writes into Laravel's
 *    `storage/framework`, which always exists; a Symfony app has no such directory, and a
 *    failed write leaves the device on a stale baked list with nothing logged.
 *    {@see NativeRouteManifest::writeRuntimeDump()} creates it and throws.
 *
 * **Unverified:** that a device then boots the screens listed. Everything here is checked
 * against upstream's Kotlin and Swift by reading them, and by tests over the file shapes —
 * there is no Android SDK or Xcode in this environment to check it against a running app.
 */
#[AsCommand(name: 'native:mobile:manifest', description: 'Write the native-route manifest (bundle_meta.json and the runtime dump)')]
final class MobileManifestCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly NativeRouteManifest $manifest,
        private readonly BundleMetaWriter $metaWriter = new BundleMetaWriter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'android, ios or both', 'both')
            ->addOption('storage-root', null, InputOption::VALUE_REQUIRED,
                'Root the runtime dump is written under; the tail storage/framework/native_routes.json is fixed by the hosts. Defaults to the project directory.')
            ->addOption('version-code', null, InputOption::VALUE_REQUIRED, 'Build number, part of the composite identity the hosts compare', '1')
            ->addOption('runtime-mode', null, InputOption::VALUE_REQUIRED, 'persistent or classic, as the hosts read it from bundle_meta.json', 'persistent')
            ->addOption('force-web', null, InputOption::VALUE_NONE,
                'Bake entry_mode=web, forcing the legacy WebView path whatever the route list says. Also honoured from NATIVEPHP_BOOT_MODE=web.')
            ->addOption('skip-dump', null, InputOption::VALUE_NONE, 'Bake only; do not write the runtime dump')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print both documents and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $platform = (string) $input->getOption('platform');

        if ('both' !== $platform && null === MobilePlatform::tryFromName($platform)) {
            $io->error(sprintf('Unknown platform "%s". Choose one of: %s, both.', $platform, implode(', ', MobilePlatform::names())));

            return Command::INVALID;
        }

        // The escape hatch is upstream's, spelled exactly as both BootPlanners read it, so
        // an existing NativePHP project's habits keep working here.
        $forceWeb = (bool) $input->getOption('force-web') || 'web' === getenv('NATIVEPHP_BOOT_MODE');
        $dryRun = (bool) $input->getOption('dry-run');

        $fragment = $this->manifest->bundleMetaFragment($forceWeb);
        $dump = $this->manifest->runtimeDump();

        // version_code as a string, where upstream writes a number. Kotlin reads it with
        // `obj.get("version_code").toString()` so either works there, and no Swift source
        // reads it out of this file at all — iOS takes the build number from the staged
        // .env instead. A string is the safer of the two given how the *version* key
        // behaves, and keeps the file uniform.
        $extra = [
            'version_code' => (string) $input->getOption('version-code'),
            'runtime_mode' => (string) $input->getOption('runtime-mode'),
        ];

        $io->definitionList(
            ['version' => $this->manifest->version()],
            ['entry_mode' => $fragment['entry_mode']],
            ['native routes' => \count($fragment[NativeRouteManifest::BAKED_ROUTES_KEY]).' declared'],
        );

        if ([] === $fragment[NativeRouteManifest::BAKED_ROUTES_KEY]) {
            $io->note([
                'No native screens are declared, so every path boots the WebView. That is the',
                'supported path for a Symfony app today (MOBILE-ANALYSIS.md §1) — the empty list',
                'is still baked deliberately, because a *missing* native_routes key and an empty',
                'one are not the same thing to iOS.',
            ]);
        }

        foreach ($fragment[NativeRouteManifest::BAKED_ROUTES_KEY] as $pattern) {
            $io->text(' • '.$pattern);
        }

        if ($dryRun) {
            $io->section('bundle_meta.json fragment (not written)');
            $io->writeln(json_encode([...$extra, ...$fragment], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            $io->section(NativeRouteManifest::RUNTIME_DUMP_RELATIVE_PATH.' (not written)');
            $io->writeln(json_encode($dump, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            $io->comment('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        // -- bake ----------------------------------------------------------------
        $baked = [];

        foreach (MobilePlatform::cases() as $case) {
            if ('both' !== $platform && $case->value !== $platform) {
                continue;
            }

            if (!is_dir($case->projectPath($this->projectDir))) {
                $io->warning(sprintf('No %s project at %s — nothing to bake into. Run native:mobile:install.', $case->label(), $case->projectPath($this->projectDir)));

                continue;
            }

            $path = $case->bundleMetaPath($this->projectDir);

            try {
                $result = $this->metaWriter->write($path, $fragment, $extra);
            } catch (\RuntimeException $e) {
                // Loudly: every failure mode this guards against is invisible on device.
                $io->error([sprintf('Refusing to write %s', $path), $e->getMessage()]);

                return Command::FAILURE;
            }

            $io->text(sprintf(' ✓ %s%s', $path, [] === $result['replaced'] ? '' : ' (replaced: '.implode(', ', $result['replaced']).')'));
            $baked[] = $case->label();
        }

        // -- runtime dump --------------------------------------------------------
        if (!$input->getOption('skip-dump')) {
            $storageRoot = (string) ($input->getOption('storage-root') ?? '') ?: $this->projectDir;

            try {
                $io->text(' ✓ '.$this->manifest->writeRuntimeDump($storageRoot));
            } catch (\RuntimeException $e) {
                $io->error([
                    $e->getMessage(),
                    'The runtime dump is how a route added since the last build reaches the device.',
                    'Failing here rather than continuing: a silent miss leaves the app on a stale list.',
                ]);

                return Command::FAILURE;
            }
        }

        if ([] === $baked) {
            $io->error([
                'Nothing was baked, so no bundle_meta.json carries a native_routes list.',
                'iOS falls back to the WebView the moment that file is missing, whatever the',
                'runtime dump says — so this is a failure even though the dump was written.',
                'Run native:mobile:install first.',
            ]);

            return Command::FAILURE;
        }

        $io->success('Manifest written for: '.implode(', ', $baked));

        return Command::SUCCESS;
    }
}
