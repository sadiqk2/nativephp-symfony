<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Build\BuildPlan;
use Native\Symfony\Mobile\Build\BundleMetaWriter;
use Native\Symfony\Mobile\Build\CommandRunnerInterface;
use Native\Symfony\Mobile\Build\MobileBuilder;
use Native\Symfony\Mobile\Build\MobilePlatform;
use Native\Symfony\Mobile\Build\PlannedCommand;
use Native\Symfony\Mobile\Build\ProcessRunner;
use Native\Symfony\Mobile\Build\Toolchain;
use Native\Symfony\Mobile\Command\MobileBuildCommand;
use Native\Symfony\Mobile\Command\MobileManifestCommand;
use Native\Symfony\Mobile\Command\MobileRunCommand;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The mobile build and run tooling.
 *
 * **What this file can and cannot prove.** There is no Android SDK and no Xcode in this
 * environment, so nothing here has ever assembled an APK, exported an archive, or watched
 * a host extract a bundle. Everything up to that boundary is real: staging, `.env`
 * cleaning, the zip, `bundle_meta.json` and the runtime dump are written to a temporary
 * project and read back. Past it, what is tested is the *plan* — the exact argv, its
 * order, the artifact paths — plus toolchain detection against fabricated SDK layouts, and
 * the `--dry-run` paths, which by construction execute nothing. That split is the whole
 * reason the plan is a value object rather than a string built inside a command.
 *
 * The manifest expectations are transcribed from NATIVE-UI-CONTRACT.md §7b and from the
 * Kotlin and Swift readers themselves, not from what looks reasonable: both key names, the
 * string-equality rule on `version`, and `native_routes` always being baked because iOS
 * falls back to the WebView the moment it is absent.
 */
final class MobileBuildTest extends TestCase
{
    private string $projectDir;

    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->projectDir = sys_get_temp_dir().'/native-mobile-build-'.bin2hex(random_bytes(6));

        $this->fs->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        // chmod back: one test makes a directory unwritable on purpose, and PHPUnit cannot
        // clean up what it cannot enter.
        foreach ([$this->projectDir, $this->projectDir.'/locked'] as $dir) {
            if (is_dir($dir)) {
                @chmod($dir, 0o777);
            }
        }

        $this->fs->remove($this->projectDir);
    }

    // ── Staging ─────────────────────────────────────

    public function testStagingSkipsExcludedPathsAndForcesTheDirectoriesSymfonyNeeds(): void
    {
        $this->givenAnApplication();
        $this->fs->dumpFile($this->projectDir.'/node_modules/left-pad/index.js', 'nope');
        $this->fs->dumpFile($this->projectDir.'/var/cache/prod/junk.php', 'nope');
        $this->fs->dumpFile($this->projectDir.'/tests/SomeTest.php', 'nope');

        $builder = $this->builder();
        $copied = $builder->stageApplication();

        self::assertGreaterThan(0, $copied);
        self::assertFileExists($builder->stagePath('bin/console'));
        self::assertFileExists($builder->stagePath('src/Kernel.php'));

        self::assertDirectoryDoesNotExist($builder->stagePath('node_modules'), 'An excluded directory must never be descended into, let alone copied.');
        self::assertFileDoesNotExist($builder->stagePath('var/cache/prod/junk.php'));
        self::assertFileDoesNotExist($builder->stagePath('tests/SomeTest.php'));

        // var/cache and var/log carry a placeholder rather than being empty: a zip does not
        // preserve an empty directory and Symfony will not boot without them.
        self::assertFileExists($builder->stagePath('var/cache/.nativephp-keep'));
        self::assertFileExists($builder->stagePath('var/log/.nativephp-keep'));
    }

    public function testTheStageDirectoryIsNeverStagedIntoItself(): void
    {
        $this->givenAnApplication();

        // The default location is under var/, inside the source tree. Without the automatic
        // exclusion this recurses on the second build, when the directory is no longer empty.
        $builder = new MobileBuilder($this->projectDir, $this->projectDir.'/var/nativephp/mobile/android');

        self::assertContains('var/nativephp/mobile/android', $builder->excludePatterns());

        $builder->stageApplication();
        $this->fs->dumpFile($builder->stagePath('marker.txt'), 'from the first build');
        $builder->stageApplication();

        // The ancestor directories are recreated empty, which is harmless — what must not
        // happen is the previous build's contents appearing inside this one.
        self::assertFileDoesNotExist($builder->stagePath('var/nativephp/mobile/android'));
        self::assertFileDoesNotExist($builder->stagePath('marker.txt'), 'The second staging clears the directory, so the first build\'s files are gone rather than nested.');
    }

    public function testAppSecretSurvivesEvenABlanketSecretGlob(): void
    {
        $this->givenAnApplication();

        $builder = new MobileBuilder(
            sourcePath: $this->projectDir,
            stagePath: $this->projectDir.'/stage',
            // Exactly what upstream's Laravel default does, and what a reasonable user might
            // add themselves. In Laravel the app key is APP_KEY and this is harmless; here it
            // matches APP_SECRET, which framework.yaml reads as %env(APP_SECRET)%, and the
            // packaged app then dies at container build where nobody sees it.
            envRemove: ['*_SECRET', 'AWS_*'],
        );

        $builder->stageApplication();
        $builder->cleanEnvironmentFile('1.2.3', 4);

        $env = (string) file_get_contents($builder->stagePath('.env'));

        self::assertStringContainsString('APP_SECRET=s3cret', $env, 'env_keep must beat env_remove, or the app does not boot at all.');
        self::assertStringNotContainsString('AWS_ACCESS_KEY_ID', $env);
        self::assertStringNotContainsString('# a comment', $env);
    }

    public function testAMultiLineQuotedValueSurvivesTheClean(): void
    {
        // Desktop's builder learned this and this one had not: splitting on newlines and
        // dropping every line without an `=` truncates the value and leaves the quote open,
        // so the staged .env stops parsing. On a device that is a launch to a 500, with the
        // build having reported success.
        $this->givenAnApplication();
        $this->fs->dumpFile($this->projectDir.'/.env', implode("\n", [
            'APP_SECRET=s3cret',
            'JWT_PASSPHRASE="line one',
            'line two"',
            'DATABASE_URL="mysql://u:p@h/db?opt=1"',
            '',
        ]));

        $builder = $this->builder();
        $builder->stageApplication();
        $builder->cleanEnvironmentFile('1.2.3', 4);

        $env = (string) file_get_contents($builder->stagePath('.env'));

        self::assertStringContainsString("JWT_PASSPHRASE=\"line one\nline two\"", $env);
        self::assertStringContainsString('DATABASE_URL="mysql://u:p@h/db?opt=1"', $env);
        self::assertSame(0, substr_count($env, '"') % 2, 'Unbalanced quotes mean the staged file no longer parses.');
    }

    public function testARemovedMultiLineValueTakesItsContinuationLinesWithIt(): void
    {
        // The lines after the first are still the removed value. Read as fresh entries, any
        // of them containing an `=` — base64 padding, JSON — looked like a key/value pair
        // and shipped inside the app: the secret the remove list existed to strip, in pieces.
        $this->givenAnApplication();
        $this->fs->dumpFile($this->projectDir.'/.env', implode("\n", [
            'APP_SECRET=keepme',
            'AWS_SESSION_TOKEN="{',
            '  "key": "sk_live_deadbeef",',
            '  "pad": "AAAA=="',
            '}"',
            'DATABASE_URL=sqlite:///db.sqlite',
            '',
        ]));

        $builder = new MobileBuilder(
            sourcePath: $this->projectDir,
            stagePath: $this->projectDir.'/stage',
            envRemove: ['AWS_*'],
        );

        $builder->stageApplication();
        $builder->cleanEnvironmentFile('1.2.3', 4);

        $env = (string) file_get_contents($builder->stagePath('.env'));

        self::assertStringNotContainsString('sk_live_deadbeef', $env);
        self::assertStringNotContainsString('AAAA==', $env);
        self::assertStringContainsString('APP_SECRET=keepme', $env);
        self::assertStringContainsString('DATABASE_URL=sqlite:///db.sqlite', $env);
    }

    public function testAnExportedSecretIsStillProtectedByTheKeepList(): void
    {
        // `export FOO=bar` is valid in Symfony's Dotenv. Taking everything before the `=`
        // gives "export APP_SECRET", which fnmatch anchors at both ends — so the keep list
        // missed it while a *_SECRET remove glob still matched, and the one variable the
        // packaged app cannot boot without was stripped.
        $this->givenAnApplication();
        $this->fs->dumpFile($this->projectDir.'/.env', "export APP_SECRET=s3cret\nexport STRIPE_SECRET=sk_live\n");

        $builder = new MobileBuilder(
            sourcePath: $this->projectDir,
            stagePath: $this->projectDir.'/stage',
            envRemove: ['*_SECRET'],
        );

        $builder->stageApplication();
        $builder->cleanEnvironmentFile('1.2.3', 4);

        $env = (string) file_get_contents($builder->stagePath('.env'));

        self::assertStringContainsString('APP_SECRET=s3cret', $env);
        self::assertStringNotContainsString('sk_live', $env);
    }

    public function testStagedEnvCarriesBothVersionKeysTheHostsCompare(): void
    {
        $this->givenAnApplication();

        $builder = $this->builder();
        $builder->stageApplication();
        $builder->cleanEnvironmentFile('1.2.3', 7);

        $env = (string) file_get_contents($builder->stagePath('.env'));

        // iOS reads this pair out of the staged .env in preference to anything else
        // (AppUpdateManager.getVersionFromZip). A missing NATIVEPHP_APP_VERSION_CODE yields
        // "1.2.3b0" against the metadata's "1.2.3b7", so the host re-extracts the entire
        // bundle on every cold launch — seconds of splash screen, every time.
        self::assertStringContainsString('NATIVEPHP_APP_VERSION=1.2.3', $env);
        self::assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=7', $env);
        self::assertStringContainsString('APP_ENV=prod', $env);
        self::assertStringContainsString('APP_DEBUG=0', $env);

        self::assertSame("1.2.3b7\n", file_get_contents($builder->writeVersionFile('1.2.3', 7)));
        self::assertSame('DEBUG', MobileBuilder::versionId('DEBUG', 9), 'DEBUG means "always re-extract" to both hosts and must not be composited.');
    }

    public function testTheDevelopersOwnEnvFileIsNeverModified(): void
    {
        $this->givenAnApplication();
        $before = (string) file_get_contents($this->projectDir.'/.env');

        $builder = $this->builder();
        $builder->stageApplication();
        $builder->cleanEnvironmentFile('1.0.0');

        self::assertSame($before, file_get_contents($this->projectDir.'/.env'));
    }

    public function testArchiveEntriesAreRelativeToTheStageRoot(): void
    {
        $this->givenAnApplication();

        $builder = $this->builder();
        $builder->stageApplication();
        $builder->cleanEnvironmentFile('1.0.0');
        $builder->writeVersionFile('1.0.0');

        $zipPath = $this->projectDir.'/out/laravel_bundle.zip';
        $entries = $builder->createBundleArchive($zipPath);

        self::assertGreaterThan(0, $entries);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));

        $names = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        // No wrapping directory: the hosts extract straight into their app directory, so one
        // extra level strands the whole application and the symptom is a blank screen.
        self::assertContains('.version', $names);
        self::assertContains('bin/console', $names);
        self::assertNotContains('stage/.version', $names);
    }

    // ── bundle_meta.json: the two guards from §7b ───

    public function testBothShapesUseTheirOwnKeyNameForTheSameList(): void
    {
        $manifest = new NativeRouteManifest($this->registryWith('/', '/items/{id}'), '2.0.0');
        $writer = new BundleMetaWriter();

        $path = $this->projectDir.'/bundle_meta.json';
        $writer->write($path, $manifest->bundleMetaFragment(), ['version_code' => '3']);

        /** @var array<string, mixed> $baked */
        $baked = json_decode((string) file_get_contents($path), true);

        // native_routes baked, routes at runtime. Upstream's inconsistency; each reader
        // depends on its own name, so neither can be "tidied up".
        self::assertSame(['/', '/items/{id}'], $baked[NativeRouteManifest::BAKED_ROUTES_KEY]);
        self::assertArrayNotHasKey('routes', $baked);
        self::assertSame('auto', $baked['entry_mode']);
        self::assertSame('2.0.0', $baked['version']);

        $dump = $manifest->runtimeDump();
        self::assertSame(['/', '/items/{id}'], $dump[NativeRouteManifest::RUNTIME_ROUTES_KEY]);
        self::assertArrayNotHasKey('native_routes', $dump);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableVersions(): iterable
    {
        // A JSON number fails iOS's `as? String` cast and compares as "" — which is also the
        // missing-key fallback, so an unversioned runtime dump then wins over the bake.
        yield 'a number' => [1];
        yield 'a float' => [1.5];
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('unusableVersions')]
    public function testAVersionTheDeviceWouldMisreadIsRefused(mixed $version): void
    {
        $writer = new BundleMetaWriter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-empty string version/');

        $writer->write($this->projectDir.'/bundle_meta.json', ['version' => $version, 'native_routes' => []]);
    }

    public function testAManifestWithoutARouteListIsRefused(): void
    {
        $writer = new BundleMetaWriter();

        // An *empty* list is a valid answer — "no native screens" — but an absent key sends
        // iOS straight to the WebView whatever the runtime dump says.
        $writer->write($this->projectDir.'/ok.json', ['version' => '1.0.0', 'native_routes' => []]);
        self::assertFileExists($this->projectDir.'/ok.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/native_routes/');

        $writer->write($this->projectDir.'/bad.json', ['version' => '1.0.0']);
    }

    public function testMergingKeepsBuildOwnedKeysAndReportsWhatItReplaced(): void
    {
        $path = $this->projectDir.'/bundle_meta.json';

        // As an upstream build would have left it: a numeric version, plus fields the route
        // manifest has no business changing.
        $this->fs->dumpFile($path, json_encode([
            'version' => 1,
            'version_code' => 1,
            'bifrost_app_id' => 'abc123',
            'runtime_mode' => 'persistent',
            'native_routes' => ['/old'],
        ]));

        $writer = new BundleMetaWriter();
        $result = $writer->write($path, (new NativeRouteManifest($this->registryWith('/new'), '9.9.9'))->bundleMetaFragment(), ['version_code' => '2']);

        self::assertSame('abc123', $result['written']['bifrost_app_id'], 'Unrelated keys survive: dropping bifrost_app_id would silently disable OTA updates.');
        self::assertSame('9.9.9', $result['written']['version'], 'A numeric version left by an upstream build is repaired, not preserved.');
        self::assertSame(['/new'], $result['written']['native_routes']);
        self::assertTrue($result['existed']);
        self::assertSame(['version_code', 'version', 'native_routes'], $result['replaced']);
    }

    public function testAnUnparseableManifestIsTreatedAsAbsentRatherThanFatal(): void
    {
        $path = $this->projectDir.'/bundle_meta.json';
        $this->fs->dumpFile($path, '{ half a fi');

        $writer = new BundleMetaWriter();

        self::assertNull($writer->read($path));

        // A half-written build artifact must not leave the project with no way forward but
        // deleting a file by hand.
        $result = $writer->write($path, ['version' => '1.0.0', 'entry_mode' => 'auto', 'native_routes' => []]);
        self::assertFalse($result['existed']);
    }

    // ── native:mobile:manifest ──────────────────────

    public function testManifestCommandWritesBothFilesWhereTheHostsReadThem(): void
    {
        $this->givenNativeProject(MobilePlatform::Android);
        $this->givenNativeProject(MobilePlatform::Ios);

        $tester = new CommandTester(new MobileManifestCommand(
            $this->projectDir,
            new NativeRouteManifest($this->registryWith('/', '/items/{id?}'), '1.4.2'),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        foreach ([MobilePlatform::Android, MobilePlatform::Ios] as $platform) {
            /** @var array<string, mixed> $meta */
            $meta = json_decode((string) file_get_contents($platform->bundleMetaPath($this->projectDir)), true);

            self::assertSame(['/', '/items/{id?}'], $meta['native_routes']);
            self::assertSame('1.4.2', $meta['version']);
            self::assertIsString($meta['version'], 'iOS reads this with `as? String`.');
            self::assertSame('auto', $meta['entry_mode']);
            self::assertSame('persistent', $meta['runtime_mode']);
        }

        // The dump's tail is fixed by both hosts, and its directory does not exist in a
        // Symfony app — creating it is this command's job or the dump silently never appears.
        $dumpPath = $this->projectDir.'/'.NativeRouteManifest::RUNTIME_DUMP_RELATIVE_PATH;
        self::assertFileExists($dumpPath);

        /** @var array<string, mixed> $dump */
        $dump = json_decode((string) file_get_contents($dumpPath), true);
        self::assertSame(['/', '/items/{id?}'], $dump['routes']);
        self::assertSame('1.4.2', $dump['version'], 'The dump is honoured only when its version string-equals the baked one.');
    }

    public function testManifestCommandForcesTheWebEscapeHatch(): void
    {
        $this->givenNativeProject(MobilePlatform::Android);

        $tester = new CommandTester(new MobileManifestCommand(
            $this->projectDir,
            new NativeRouteManifest($this->registryWith('/'), '1.0.0'),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force-web' => true]));

        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) file_get_contents(MobilePlatform::Android->bundleMetaPath($this->projectDir)), true);

        // Only the exact string "web" forces the legacy path on either platform.
        self::assertSame('web', $meta['entry_mode']);
        self::assertSame(['/'], $meta['native_routes'], 'The escape hatch changes the boot decision, not the route list.');
    }

    public function testManifestCommandDryRunWritesNothing(): void
    {
        $this->givenNativeProject(MobilePlatform::Android);

        $tester = new CommandTester(new MobileManifestCommand(
            $this->projectDir,
            new NativeRouteManifest($this->registryWith('/'), '1.0.0'),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));

        self::assertFileDoesNotExist(MobilePlatform::Android->bundleMetaPath($this->projectDir));
        self::assertFileDoesNotExist($this->projectDir.'/'.NativeRouteManifest::RUNTIME_DUMP_RELATIVE_PATH);
        self::assertStringContainsString('native_routes', $tester->getDisplay());
        self::assertStringContainsString('"routes"', $tester->getDisplay());
    }

    public function testManifestCommandFailsWhenThereIsNoProjectToBakeInto(): void
    {
        $tester = new CommandTester(new MobileManifestCommand(
            $this->projectDir,
            new NativeRouteManifest($this->registryWith('/'), '1.0.0'),
        ));

        // Android could boot native from the dump alone; iOS cannot, so a dump without a bake
        // is a silent fallback to the WebView. Reporting success here would hide that.
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('native:mobile:install', $tester->getDisplay());
    }

    public function testManifestCommandFailsLoudlyWhenTheDumpDirectoryCannotBeCreated(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root ignores directory permissions, so the failure cannot be provoked.');
        }

        $this->givenNativeProject(MobilePlatform::Android);

        $locked = $this->projectDir.'/locked';
        $this->fs->mkdir($locked);
        chmod($locked, 0o555);

        $tester = new CommandTester(new MobileManifestCommand(
            $this->projectDir,
            new NativeRouteManifest($this->registryWith('/'), '1.0.0'),
        ));

        // Upstream writes into Laravel's always-present storage/framework and does not check.
        // Here the write can genuinely fail, and a silent miss leaves the device on a stale
        // baked list with nothing logged.
        self::assertSame(Command::FAILURE, $tester->execute(['--storage-root' => $locked]));
        self::assertStringContainsString('Cannot create', $tester->getDisplay());
    }

    public function testManifestCommandRejectsAnUnknownPlatform(): void
    {
        $tester = new CommandTester(new MobileManifestCommand(
            $this->projectDir,
            new NativeRouteManifest($this->registryWith('/'), '1.0.0'),
        ));

        self::assertSame(Command::INVALID, $tester->execute(['--platform' => 'blackberry']));
    }

    // ── Toolchain detection ─────────────────────────

    public function testAndroidToolchainIsFoundOnPathAndUnderTheSdk(): void
    {
        $this->givenNativeProject(MobilePlatform::Android);

        $sdk = $this->projectDir.'/fake-sdk';
        $this->givenExecutable($sdk.'/platform-tools/adb');
        $bin = $this->projectDir.'/fake-bin';
        $this->givenExecutable($bin.'/java');

        $report = (new Toolchain(['PATH' => $bin, 'ANDROID_HOME' => $sdk], 'Linux'))
            ->inspect(MobilePlatform::Android, $this->projectDir);

        self::assertTrue($report->isSatisfied(), implode("\n", $report->lines()));

        // adb is deliberately looked for under the SDK as well as on PATH: Android Studio
        // installs platform-tools and adds nothing to a shell profile.
        self::assertSame($sdk.'/platform-tools/adb', $report->pathFor('adb'));
        self::assertSame($sdk, $report->pathFor('Android SDK'));
        self::assertSame($this->projectDir.'/nativephp/android/gradlew', $report->pathFor('gradlew'));
    }

    public function testMissingAndroidToolsAreReportedWithSomethingActionable(): void
    {
        $report = (new Toolchain(['PATH' => '/nonexistent'], 'Linux'))->inspect(MobilePlatform::Android, $this->projectDir);

        self::assertFalse($report->isSatisfied());
        self::assertSame(['Android project', 'gradlew', 'Android SDK', 'adb', 'JDK'], array_map(static fn ($c) => $c->name, $report->missing()));

        $hints = implode(' ', array_map(static fn ($c) => $c->hint, $report->missing()));

        self::assertStringContainsString('ANDROID_HOME', $hints);
        self::assertStringContainsString('platform-tools', $hints);
        self::assertStringContainsString('JAVA_HOME', $hints);
        self::assertStringContainsString('native:mobile:install', $hints);
    }

    public function testIosOnANonMacHostSaysThereIsNothingToInstall(): void
    {
        $report = (new Toolchain([], 'Linux'))->inspect(MobilePlatform::Ios, $this->projectDir);

        $host = $report->get('macOS host');

        self::assertNotNull($host);
        self::assertFalse($host->isSatisfied());
        self::assertStringContainsString('only be built on macOS', $host->hint);
        self::assertStringContainsString('Nothing to install', $host->hint, 'Apple ships no cross-platform toolchain; offering a remedy would be a lie.');
    }

    public function testTheSdkIsAlsoLookedForWhereEachPlatformPutsIt(): void
    {
        $home = $this->projectDir.'/home';
        $this->fs->mkdir($home.'/Library/Android/sdk');
        $this->fs->mkdir($home.'/Android/Sdk');

        self::assertSame($home.'/Library/Android/sdk', (new Toolchain(['HOME' => $home], 'Darwin'))->androidSdkPath());
        self::assertSame($home.'/Android/Sdk', (new Toolchain(['HOME' => $home], 'Linux'))->androidSdkPath());

        // The environment wins over the convention: a developer with two SDKs has said which.
        $explicit = $this->projectDir.'/elsewhere';
        $this->fs->mkdir($explicit);
        self::assertSame($explicit, (new Toolchain(['HOME' => $home, 'ANDROID_SDK_ROOT' => $explicit], 'Linux'))->androidSdkPath());
    }

    // ── The plans ───────────────────────────────────

    public function testAndroidRunPlanAssemblesInstallsAndLaunches(): void
    {
        $plan = BuildPlan::androidRun($this->projectDir, 'com.example.app', 'emulator-5554');

        self::assertCount(3, $plan);
        self::assertSame(['./gradlew', 'assembleDebug'], $plan[0]->argv);
        self::assertSame($this->projectDir.'/nativephp/android', $plan[0]->cwd);

        // -s names the target: with more than one device attached Gradle's own installDebug
        // is ambiguous. -r reinstalls over an existing copy, -d permits a version-code
        // downgrade, which a DEBUG build over a released one needs.
        self::assertSame([
            'adb', '-s', 'emulator-5554', 'install', '-r', '-d',
            $this->projectDir.'/nativephp/android/app/build/outputs/apk/debug/app-debug.apk',
        ], $plan[1]->argv);

        self::assertSame([
            'adb', '-s', 'emulator-5554', 'shell', 'am', 'start', '-n',
            'com.example.app/'.BuildPlan::ANDROID_MAIN_ACTIVITY,
        ], $plan[2]->argv);

        // A debug APK carries the whole PHP runtime; a cold emulator takes minutes to accept
        // it, well past any default.
        self::assertSame(600, $plan[1]->timeout);
        self::assertNull($plan[0]->timeout, 'A cold Gradle daemon legitimately runs for many minutes.');
    }

    public function testDeviceSerialsAndAppIdsTravelAsArgumentsNotShellStrings(): void
    {
        $plan = BuildPlan::androidRun($this->projectDir, 'com.example.app', 'a b; rm -rf /');

        // Upstream interpolates these into a shell string. Here the serial is one argv entry,
        // so it is inert — and the displayed form is quoted only for copy-pasting.
        self::assertContains('a b; rm -rf /', $plan[1]->argv);
        self::assertStringContainsString("'a b; rm -rf /'", $plan[1]->display());
    }

    public function testAndroidBuildTargetsAndArtifactsMatchGradlesOutputLayout(): void
    {
        self::assertSame('assembleRelease', BuildPlan::androidGradleTask('release'));
        self::assertSame('bundleRelease', BuildPlan::androidGradleTask('bundle'));

        self::assertSame(['./gradlew', 'assembleRelease'], BuildPlan::androidBuild($this->projectDir)[0]->argv);
        self::assertSame(['./gradlew', 'bundleRelease'], BuildPlan::androidBuild($this->projectDir, aab: true)[0]->argv);

        $base = $this->projectDir.'/nativephp/android/app/build/outputs';
        self::assertSame($base.'/apk/release/app-release.apk', BuildPlan::androidArtifact($this->projectDir, 'release'));
        self::assertSame($base.'/bundle/release/app-release.aab', BuildPlan::androidArtifact($this->projectDir, 'bundle'));

        $this->expectException(\InvalidArgumentException::class);
        BuildPlan::androidArtifact($this->projectDir, 'profileable');
    }

    public function testIosRunPlanBuildsBootsInstallsAndLaunches(): void
    {
        $plan = BuildPlan::iosRun($this->projectDir, 'com.example.app', 'ABC-123');

        self::assertSame('xcodebuild', $plan[0]->program());
        self::assertContains('-destination', $plan[0]->argv);
        self::assertContains('id=ABC-123', $plan[0]->argv);
        self::assertContains(BuildPlan::IOS_SIMULATOR_SCHEME, $plan[0]->argv);
        self::assertContains('iphonesimulator', $plan[0]->argv);

        self::assertSame(['xcrun', 'simctl', 'boot', 'ABC-123'], $plan[1]->argv);
        self::assertTrue($plan[1]->tolerateFailure, 'simctl boot fails when the simulator is already booted, which is the state we wanted.');

        self::assertSame(['xcrun', 'simctl', 'install', 'ABC-123', BuildPlan::iosSimulatorApp($this->projectDir)], $plan[2]->argv);
        self::assertSame(['xcrun', 'simctl', 'launch', 'ABC-123', 'com.example.app'], $plan[3]->argv);
    }

    public function testIosBuildArchivesAndOnlyExportsWhenGivenAPlist(): void
    {
        $archiveOnly = BuildPlan::iosBuild($this->projectDir);

        self::assertCount(1, $archiveOnly, 'Exporting an .ipa needs signing configuration; archiving does not.');
        self::assertContains('archive', $archiveOnly[0]->argv);
        self::assertContains(BuildPlan::iosArchivePath($this->projectDir), $archiveOnly[0]->argv);

        $withExport = BuildPlan::iosBuild($this->projectDir, '/tmp/exportOptions.plist');

        self::assertCount(2, $withExport);
        self::assertSame([
            'xcodebuild', '-exportArchive',
            '-archivePath', BuildPlan::iosArchivePath($this->projectDir),
            '-exportPath', BuildPlan::iosExportPath($this->projectDir),
            '-exportOptionsPlist', '/tmp/exportOptions.plist',
        ], $withExport[1]->argv);
    }

    public function testTheWorkspaceIsChosenByWhatCocoaPodsLeftBehind(): void
    {
        self::assertSame('NativePHP.xcodeproj/project.xcworkspace', BuildPlan::iosWorkspace($this->projectDir));

        $this->fs->dumpFile($this->projectDir.'/nativephp/ios/NativePHP.xcworkspace/contents.xcworkspacedata', '<Workspace/>');

        self::assertSame('NativePHP.xcworkspace', BuildPlan::iosWorkspace($this->projectDir));
    }

    // ── native:mobile:run ───────────────────────────

    public function testRunDryRunExecutesNothingAndSaysSo(): void
    {
        $runner = new RecordingRunner();
        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', new Toolchain([], 'Linux'), $runner));

        self::assertSame(Command::SUCCESS, $tester->execute(['platform' => 'android', '--dry-run' => true]));
        self::assertSame([], $runner->executed, 'A dry run must not execute a single command.');

        $display = $tester->getDisplay();
        self::assertStringContainsString('gradlew assembleDebug', $display);
        self::assertStringContainsString('adb install -r -d', $display);
        self::assertStringContainsString('nothing was executed', $display);
        self::assertStringContainsString('toolchain is incomplete', $display, 'A dry run still has to be honest about what a real run would do.');
    }

    public function testRunRefusesToStartWithAnIncompleteToolchain(): void
    {
        $runner = new RecordingRunner();
        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', new Toolchain(['PATH' => '/nonexistent'], 'Linux'), $runner));

        self::assertSame(Command::FAILURE, $tester->execute(['platform' => 'android']));
        self::assertSame([], $runner->executed);
        self::assertStringContainsString('toolchain is incomplete', $tester->getDisplay());
        self::assertStringContainsString('ANDROID_HOME', $tester->getDisplay());
    }

    public function testRunRejectsAnUnknownPlatform(): void
    {
        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', new Toolchain([], 'Linux'), new RecordingRunner()));

        self::assertSame(Command::INVALID, $tester->execute(['platform' => 'windows-phone']));
    }

    public function testAnIosRunNeedsAConcreteUdidUnlessItIsADryRun(): void
    {
        $toolchain = new Toolchain([], 'Linux');

        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', $toolchain, new RecordingRunner()));
        self::assertSame(Command::INVALID, $tester->execute(['platform' => 'ios']));
        self::assertStringContainsString('simctl list devices', $tester->getDisplay());

        // In a dry run a placeholder keeps the output complete, and is unmistakably a template.
        $dry = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', $toolchain, new RecordingRunner()));
        self::assertSame(Command::SUCCESS, $dry->execute(['platform' => 'ios', '--dry-run' => true]));
        self::assertStringContainsString('REPLACE-WITH-SIMULATOR-UDID', $dry->getDisplay());
    }

    public function testRunExecutesThePlanInOrderWithResolvedBinaries(): void
    {
        $toolchain = $this->givenASatisfiedAndroidToolchain();
        $runner = new RecordingRunner();

        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', $toolchain, $runner));

        self::assertSame(Command::SUCCESS, $tester->execute(['platform' => 'android', '--device' => 'emulator-5554']));
        self::assertCount(3, $runner->executed);

        // The bare names are replaced by what detection found: the tools are routinely absent
        // from PATH even on a working machine.
        self::assertSame($this->projectDir.'/nativephp/android/gradlew', $runner->executed[0]->program());
        self::assertSame($this->projectDir.'/fake-sdk/platform-tools/adb', $runner->executed[1]->program());
        self::assertStringContainsString('never been exercised', $tester->getDisplay());
    }

    public function testRunStopsAtTheFirstFailingStep(): void
    {
        $toolchain = $this->givenASatisfiedAndroidToolchain();
        $runner = new RecordingRunner([1]);

        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', $toolchain, $runner));

        self::assertSame(Command::FAILURE, $tester->execute(['platform' => 'android']));
        self::assertCount(1, $runner->executed, 'Installing onto a device after a failed compile would install the previous build.');
        self::assertStringContainsString('Stopping;', $tester->getDisplay());
    }

    public function testAToleratedFailureDoesNotStopTheSequence(): void
    {
        // A fabricated macOS host: the only way to exercise the iOS path at all from here.
        $bin = $this->projectDir.'/fake-bin';
        $this->givenExecutable($bin.'/xcodebuild');
        $this->givenExecutable($bin.'/xcrun');
        $this->givenNativeProject(MobilePlatform::Ios);

        $toolchain = new Toolchain(['PATH' => $bin], 'Darwin');
        // simctl boot is step 2 and returns 1: already-booted is the state we wanted.
        $runner = new RecordingRunner([0, 1, 0, 0]);

        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', $toolchain, $runner));

        self::assertSame(Command::SUCCESS, $tester->execute(['platform' => 'ios', '--udid' => 'ABC-123']));
        self::assertCount(4, $runner->executed);
        self::assertStringContainsString('which this step tolerates', $tester->getDisplay());
    }

    public function testListDevicesOffersTheCommandThatDiagnosesAFailedRun(): void
    {
        $tester = new CommandTester(new MobileRunCommand($this->projectDir, 'com.example.app', new Toolchain([], 'Linux'), new RecordingRunner()));

        $tester->execute(['platform' => 'android', '--list-devices' => true, '--dry-run' => true]);

        self::assertStringContainsString('adb devices -l', $tester->getDisplay());
    }

    // ── native:mobile:build ─────────────────────────

    public function testBuildStageOnlyProducesTheArchiveAndItsManifest(): void
    {
        $this->givenAnApplication();
        $this->givenNativeProject(MobilePlatform::Android);

        $runner = new RecordingRunner();
        $tester = new CommandTester($this->buildCommand($runner, '1.2.3', '/', '/items/{id}'));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'platform' => 'android',
            '--stage-only' => true,
            '--skip-composer' => true,
            '--skip-cache-warmup' => true,
            '--version-code' => '4',
        ]));

        self::assertSame([], $runner->executed, '--stage-only must stop before Gradle, which is also where verification stops.');

        $zip = MobilePlatform::Android->bundleZipPath($this->projectDir);
        self::assertFileExists($zip);
        self::assertStringEndsWith('/nativephp/android/app/src/main/assets/laravel_bundle.zip', $zip, 'The name is a literal in LaravelEnvironment.kt.');

        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) file_get_contents(MobilePlatform::Android->bundleMetaPath($this->projectDir)), true);

        self::assertSame(['/', '/items/{id}'], $meta['native_routes']);
        self::assertSame('1.2.3', $meta['version']);
        self::assertSame('4', $meta['version_code']);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip));
        self::assertSame("1.2.3b4\n", $archive->getFromName('.version'));
        self::assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=4', (string) $archive->getFromName('.env'));
        self::assertStringContainsString('APP_SECRET=s3cret', (string) $archive->getFromName('.env'));
        $archive->close();
    }

    public function testBuildWritesTheVersionFileIosReadsBesideItsArchive(): void
    {
        $this->givenAnApplication();
        $this->givenNativeProject(MobilePlatform::Ios);

        $tester = new CommandTester($this->buildCommand(new RecordingRunner(), '2.0.0'));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'platform' => 'ios',
            '--stage-only' => true,
            '--skip-composer' => true,
            '--skip-cache-warmup' => true,
        ]));

        self::assertFileExists(MobilePlatform::Ios->bundleZipPath($this->projectDir));
        self::assertSame('2.0.0b1', file_get_contents((string) MobilePlatform::Ios->bundledVersionPath($this->projectDir)));

        // Android reads .version from inside the extracted tree instead, so it has no file
        // beside the archive.
        self::assertNull(MobilePlatform::Android->bundledVersionPath($this->projectDir));
    }

    public function testBuildBakesTheWebEscapeHatchWhenAsked(): void
    {
        $this->givenAnApplication();
        $this->givenNativeProject(MobilePlatform::Android);

        $tester = new CommandTester($this->buildCommand(new RecordingRunner(), '1.0.0', '/'));

        $tester->execute([
            'platform' => 'android',
            '--stage-only' => true,
            '--skip-composer' => true,
            '--skip-cache-warmup' => true,
            '--force-web' => true,
        ]);

        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) file_get_contents(MobilePlatform::Android->bundleMetaPath($this->projectDir)), true);

        self::assertSame('web', $meta['entry_mode']);
    }

    public function testBuildDryRunTouchesNothing(): void
    {
        $this->givenAnApplication();
        $this->givenNativeProject(MobilePlatform::Android);

        $runner = new RecordingRunner();
        $tester = new CommandTester($this->buildCommand($runner, '1.0.0'));

        self::assertSame(Command::SUCCESS, $tester->execute(['platform' => 'android', '--dry-run' => true]));

        self::assertSame([], $runner->executed);
        self::assertFileDoesNotExist(MobilePlatform::Android->bundleZipPath($this->projectDir));
        self::assertFileDoesNotExist(MobilePlatform::Android->bundleMetaPath($this->projectDir));
        self::assertDirectoryDoesNotExist($this->projectDir.'/var/nativephp');

        $display = $tester->getDisplay();
        self::assertStringContainsString('APP_SECRET must survive', $display);
        self::assertStringContainsString('gradlew assembleRelease', $display);
        self::assertStringContainsString('nothing was staged, written or compiled', $display);
    }

    public function testBuildRefusesWhenTheNativeProjectIsNotInstalled(): void
    {
        $this->givenAnApplication();

        $tester = new CommandTester($this->buildCommand(new RecordingRunner(), '1.0.0'));

        self::assertSame(Command::INVALID, $tester->execute(['platform' => 'android', '--stage-only' => true]));
        self::assertStringContainsString('native:mobile:install', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->projectDir.'/var/nativephp', 'Nothing should be staged when there is nowhere to put it.');
    }

    public function testBuildStopsWhenAStagingCommandFails(): void
    {
        $this->givenAnApplication();
        $this->givenNativeProject(MobilePlatform::Android);

        $runner = new RecordingRunner([1]);
        $tester = new CommandTester($this->buildCommand($runner, '1.0.0'));

        self::assertSame(Command::FAILURE, $tester->execute(['platform' => 'android', '--stage-only' => true, '--skip-cache-warmup' => true]));
        self::assertSame(['composer'], array_map(static fn (PlannedCommand $c) => $c->program(), $runner->executed));
        self::assertFileDoesNotExist(MobilePlatform::Android->bundleZipPath($this->projectDir), 'A bundle assembled on a failed composer install would ship a dev vendor tree.');
    }

    public function testBuildReportsAMissingToolchainWithoutLosingTheStagedWork(): void
    {
        $this->givenAnApplication();
        $this->givenNativeProject(MobilePlatform::Android);

        $tester = new CommandTester($this->buildCommand(new RecordingRunner(), '1.0.0', '/'));

        self::assertSame(Command::FAILURE, $tester->execute([
            'platform' => 'android',
            '--skip-composer' => true,
            '--skip-cache-warmup' => true,
        ]));

        // The distinction that matters: the archive is written, so a machine with the
        // toolchain can finish from here.
        self::assertFileExists(MobilePlatform::Android->bundleZipPath($this->projectDir));
        self::assertStringContainsString('ARE written', $tester->getDisplay());
    }

    public function testGeneratedExportOptionsAreDevelopmentSigningOnly(): void
    {
        $builder = $this->builder();
        $path = $builder->writeExportOptions($this->projectDir.'/exportOptions.plist', teamId: 'TEAM123');
        $plist = (string) file_get_contents($path);

        self::assertStringContainsString('<key>method</key>', $plist);
        self::assertStringContainsString('<string>development</string>', $plist);
        self::assertStringContainsString('<string>TEAM123</string>', $plist);

        // Anything beyond development signing needs a real Apple account and keychain
        // identity, neither of which can be exercised here.
        self::assertStringNotContainsString('app-store', $plist);
    }

    public function testGradlePropertiesUseForwardSlashesEvenForAWindowsSdk(): void
    {
        $this->givenNativeProject(MobilePlatform::Android);

        $path = $this->builder()->writeAndroidLocalProperties($this->projectDir, 'C:\\Users\\dev\\AppData\\Local\\Android\\Sdk');

        // A backslash is an escape in a Gradle properties file, so a literal Windows path
        // silently resolves to nonsense.
        self::assertSame("sdk.dir=C:/Users/dev/AppData/Local/Android/Sdk\n", file_get_contents($path));
    }

    // ── The process plumbing ────────────────────────

    public function testTheRunnerStreamsOutputAndReturnsTheExitCode(): void
    {
        $collected = '';
        $exit = (new ProcessRunner())->run(
            new PlannedCommand(['/bin/sh', '-c', 'echo out; echo err 1>&2; exit 3'], sys_get_temp_dir(), 'test'),
            static function (string $type, string $chunk) use (&$collected): void {
                $collected .= $type.':'.trim($chunk).' ';
            },
        );

        self::assertSame(3, $exit);
        self::assertStringContainsString('out:out', $collected);
        self::assertStringContainsString('err:err', $collected);
    }

    public function testAMissingProgramIsReportedAs127RatherThanThrowing(): void
    {
        // The toolchain report is where a missing binary is explained; by the time a command
        // runs, an absent program is just a failed step.
        self::assertSame(127, (new ProcessRunner())->run(
            new PlannedCommand([$this->projectDir.'/definitely-not-here'], sys_get_temp_dir(), 'test'),
        ));
    }

    public function testACommandThatOutlivesItsTimeoutIsKilled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/timeout/');

        (new ProcessRunner())->run(new PlannedCommand(['/bin/sh', '-c', 'sleep 5'], sys_get_temp_dir(), 'test', timeout: 1));
    }

    public function testAPlannedCommandNeedsAProgram(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PlannedCommand([], '/tmp', 'nothing');
    }

    // ── Fixtures ────────────────────────────────────

    private function builder(): MobileBuilder
    {
        return new MobileBuilder($this->projectDir, $this->projectDir.'/stage');
    }

    private function buildCommand(CommandRunnerInterface $runner, string $version, string ...$patterns): MobileBuildCommand
    {
        return new MobileBuildCommand(
            $this->projectDir,
            $version,
            new NativeRouteManifest($this->registryWith(...$patterns), $version),
            new Toolchain(['PATH' => '/nonexistent'], 'Linux'),
            $runner,
        );
    }

    private function registryWith(string ...$patterns): NativeRouteRegistry
    {
        $registry = new NativeRouteRegistry();

        foreach ($patterns as $i => $pattern) {
            $registry->register($pattern, self::class, 'screen'.$i);
        }

        return $registry;
    }

    /** A minimal Symfony application: enough for staging to have something to do. */
    private function givenAnApplication(): void
    {
        $this->fs->dumpFile($this->projectDir.'/bin/console', "#!/usr/bin/env php\n");
        $this->fs->chmod($this->projectDir.'/bin/console', 0o755);
        $this->fs->dumpFile($this->projectDir.'/src/Kernel.php', '<?php');
        $this->fs->dumpFile($this->projectDir.'/composer.json', '{}');
        $this->fs->dumpFile($this->projectDir.'/.env', implode("\n", [
            '# a comment',
            'APP_ENV=dev',
            'APP_DEBUG=1',
            'APP_SECRET=s3cret',
            'AWS_ACCESS_KEY_ID=AKIA',
            'DATABASE_URL=sqlite:///app.db',
        ])."\n");
    }

    private function givenNativeProject(MobilePlatform $platform): void
    {
        $this->fs->mkdir($platform->assetsPath($this->projectDir));

        if (MobilePlatform::Android === $platform) {
            $this->fs->dumpFile($platform->projectPath($this->projectDir).'/gradlew', "#!/bin/sh\n");
            $this->fs->chmod($platform->projectPath($this->projectDir).'/gradlew', 0o755);
        }
    }

    private function givenASatisfiedAndroidToolchain(): Toolchain
    {
        $this->givenNativeProject(MobilePlatform::Android);
        $this->givenExecutable($this->projectDir.'/fake-sdk/platform-tools/adb');
        $this->givenExecutable($this->projectDir.'/fake-bin/java');

        return new Toolchain([
            'PATH' => $this->projectDir.'/fake-bin',
            'ANDROID_HOME' => $this->projectDir.'/fake-sdk',
        ], 'Linux');
    }

    private function givenExecutable(string $path): void
    {
        $this->fs->dumpFile($path, "#!/bin/sh\nexit 0\n");
        $this->fs->chmod($path, 0o755);
    }
}

/**
 * Records what a command would have run, and hands back the exit codes a test asks for.
 *
 * The only way to test the run and build sequences here: the real runner would need Gradle
 * and Xcode, and this environment has neither.
 */
final class RecordingRunner implements CommandRunnerInterface
{
    /** @var list<PlannedCommand> */
    public array $executed = [];

    /** @param list<int> $exitCodes Consumed in order; anything beyond the list succeeds */
    public function __construct(private array $exitCodes = [])
    {
    }

    public function run(PlannedCommand $command, ?callable $onOutput = null): int
    {
        $this->executed[] = $command;

        return array_shift($this->exitCodes) ?? 0;
    }
}
