<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Command\BuildCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `native:build`, driven end to end with npm and npx replaced by recorders.
 *
 * The command had no test that ran it: 127 of its statements were uncovered, which is
 * every line after the argument checks. Everything it decides — which npm script to
 * run, what identity it writes into package.json, which environment electron-builder
 * inherits, whether a failing hook stops the build — was only ever verified by running
 * a real 200MB package build, so in practice it was verified once.
 *
 * The stubs are shell scripts on PATH that append their argv and the interesting
 * environment to a log. That keeps the real Process, the real cwd and the real
 * environment merging in the test, and replaces only the thing that would take minutes.
 */
final class BuildCommandTest extends TestCase
{
    private string $project;
    private string $stubs;
    private string $log;
    private string $path;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/np-symfony-build-'.bin2hex(random_bytes(4));
        $this->stubs = $this->project.'/.stubs';
        $this->log = $this->project.'/.stubs/calls.log';

        $fs = new Filesystem();

        // A minimal application: enough for the stager to have something to copy.
        $fs->dumpFile($this->project.'/composer.json', '{"name":"acme/app"}');
        $fs->dumpFile($this->project.'/src/Kernel.php', '<?php // app');
        $fs->dumpFile($this->project.'/.env', "APP_ENV=dev\nAPP_SECRET=keepme\nSTRIPE_KEY=remove\n");
        $fs->dumpFile($this->project.'/public/icon.png', 'png');

        $this->installElectronProject();
        $this->installStubs();

        $this->path = (string) getenv('PATH');
        $this->setPath($this->stubs.':'.$this->path);
    }

    protected function tearDown(): void
    {
        $this->setPath($this->path);
        (new Filesystem())->remove($this->project);
    }

    // ── which npm script the command asks for ───────────────────────────────

    public function testBuildRunsTheNpmScriptForTheTargetPlatform(): void
    {
        $tester = $this->execute(['os' => 'linux', 'arch' => 'x64', '--skip-composer' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains('npm run build:linux-x64', $this->calls());
    }

    public function testPublishRunsThePublishScript(): void
    {
        $tester = $this->execute(['os' => 'mac', 'arch' => 'arm64', '--skip-composer' => true, '--publish' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains('npm run publish:mac-arm64', $this->calls());
    }

    public function testDirBuildsThroughElectronBuilderDirectly(): void
    {
        $tester = $this->execute(['os' => 'linux', 'arch' => 'x64', '--skip-composer' => true, '--dir' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains('npx electron-vite build', $this->calls());
        self::assertContains(
            'npx electron-builder --config electron-builder.mjs --linux --x64 --dir -p never',
            $this->calls(),
        );
    }

    public function testATargetUpstreamHasNoScriptForStillBuilds(): void
    {
        // Windows on ARM: upstream defines build:win-x64 but no build:win-arm64.
        $tester = $this->execute(['os' => 'win', 'arch' => 'arm64', '--skip-composer' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains('npx electron-vite build', $this->calls());
        self::assertContains(
            'npx electron-builder --config electron-builder.mjs --win --arm64 -p never',
            $this->calls(),
        );
        self::assertStringContainsString('no "build:win-arm64" script', $this->flatten($tester));
    }

    public function testPublishingATargetUpstreamHasNoScriptForPublishes(): void
    {
        $tester = $this->execute(['os' => 'win', 'arch' => 'arm64', '--skip-composer' => true, '--publish' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains(
            'npx electron-builder --config electron-builder.mjs --win --arm64 -p always',
            $this->calls(),
        );
    }

    /**
     * Every OS and architecture the command accepts has to reach something that exists.
     *
     * The npm scripts are upstream's, and upstream ships no `build:win-arm64` or
     * `publish:win-arm64` — so `native:build win arm64`, which the command accepts
     * without complaint, ended at npm's "Missing script" and an exit code, after having
     * already staged the application and reinstalled its dependencies. Whatever it runs
     * now, an `npm run` of a script the project does not define is not it.
     */
    #[DataProvider('platforms')]
    public function testEveryAcceptedTargetReachesSomethingThatExists(string $os, string $arch, bool $publish): void
    {
        $tester = $this->execute(array_filter([
            'os' => $os,
            'arch' => $arch,
            '--skip-composer' => true,
            '--publish' => $publish,
        ]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $scripts = $this->electronScripts();

        foreach ($this->calls() as $call) {
            if (str_starts_with($call, 'npm run ')) {
                $script = substr($call, \strlen('npm run '));

                self::assertArrayHasKey(
                    $script,
                    $scripts,
                    sprintf('native:build %s %s runs "npm run %s", which the Electron project does not define.', $os, $arch, $script),
                );
            }
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function platforms(): iterable
    {
        foreach (['linux', 'mac', 'win'] as $os) {
            foreach (['x64', 'arm64'] as $arch) {
                yield "build {$os} {$arch}" => [$os, $arch, false];
                yield "publish {$os} {$arch}" => [$os, $arch, true];
            }
        }
    }

    // ── what electron-builder is told ───────────────────────────────────────

    public function testTheAppIdentityReachesElectronBuilderAndPackageJson(): void
    {
        $this->execute(['os' => 'linux', '--skip-composer' => true]);

        $env = $this->environment();

        self::assertSame('Desk Pad', $env['NATIVEPHP_APP_NAME']);
        self::assertSame('desk-pad', $env['NATIVEPHP_APP_FILENAME']);
        self::assertSame('com.acme.deskpad', $env['NATIVEPHP_APP_ID']);
        self::assertSame('2.3.4', $env['NATIVEPHP_APP_VERSION']);
        self::assertSame('true', $env['NATIVEPHP_BUILDING']);
        self::assertSame($this->project, $env['APP_PATH']);

        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents($this->project.'/nativephp/electron/package.json'), true, 512, \JSON_THROW_ON_ERROR);

        // electron-builder names the artifact from package.json, not from our config.
        self::assertSame('desk-pad', $json['name']);
        self::assertSame('2.3.4', $json['version']);
        // Whatever else upstream keeps in there has to survive the rewrite.
        self::assertArrayHasKey('scripts', $json);
    }

    public function testWithTheUpdaterOffElectronBuilderIsToldNotToPublish(): void
    {
        $this->execute(['os' => 'linux', '--skip-composer' => true]);

        $env = $this->environment();

        // The mjs drops its whole `publish` block unless this reads exactly 'true'.
        self::assertSame('false', $env['NATIVEPHP_UPDATER_ENABLED']);
        self::assertSame('[]', $env['NATIVEPHP_UPDATER_CONFIG']);
    }

    public function testAnEnabledUpdaterReachesElectronBuilderAsAPublishTarget(): void
    {
        $this->execute(['os' => 'linux', '--skip-composer' => true], ['updater' => [
            'enabled' => true,
            'default' => 'github',
            'providers' => ['github' => ['driver' => 'github', 'owner' => 'sadiqk2', 'repo' => 'deskpad', 'token' => 'ghp_x']],
        ]]);

        $env = $this->environment();

        self::assertSame('true', $env['NATIVEPHP_UPDATER_ENABLED']);

        /** @var array<string, mixed> $publish */
        $publish = json_decode($env['NATIVEPHP_UPDATER_CONFIG'], true, 512, \JSON_THROW_ON_ERROR);

        // electron-builder assigns this to `publish` as it stands, so it has to be a
        // provider — not the {enabled, default, providers} tree the runtime reads.
        self::assertSame('github', $publish['provider']);
        self::assertSame('deskpad', $publish['repo']);
        self::assertArrayNotHasKey('providers', $publish);
    }

    // ── the staged application ──────────────────────────────────────────────

    public function testTheApplicationIsStagedAndItsEnvironmentCleaned(): void
    {
        $this->execute(['os' => 'linux', '--skip-composer' => true]);

        $app = $this->project.'/nativephp/build/app';

        self::assertFileExists($app.'/src/Kernel.php');
        self::assertFileExists($app.'/composer.json');

        $env = (string) file_get_contents($app.'/.env');

        self::assertStringContainsString('APP_ENV=prod', $env);
        self::assertStringContainsString('APP_SECRET=keepme', $env);
        self::assertStringNotContainsString('STRIPE_KEY', $env);
    }

    /**
     * src/main/index.js reads $NATIVEPHP_BUILD_PATH/icon.png for the dock and the Linux
     * window icon, and menuBar.ts reads the same path with icon.png swapped for
     * IconTemplate.png for the tray MenuBar::create() builds by default. Upstream's
     * build path ships all three, so a Laravel app with an empty public/ still has them.
     * Ours was assembled from nothing and the build said "electron-builder will use its
     * default" — which is only true of the installer icon, not of the three files the
     * runtime itself opens.
     */
    public function testABuildWithoutAppIconsStillCarriesTheRuntimesOwn(): void
    {
        (new Filesystem())->remove($this->project.'/public/icon.png');

        $tester = $this->execute(['os' => 'linux', '--skip-composer' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $build = $this->project.'/nativephp/build';

        self::assertSame('DEFAULT', file_get_contents($build.'/icon.png'));
        self::assertSame('DEFAULT', file_get_contents($build.'/IconTemplate.png'));
        self::assertSame('DEFAULT', file_get_contents($build.'/IconTemplate@2x.png'));
    }

    // ── failures ────────────────────────────────────────────────────────────

    public function testAFailingPreBuildHookStopsBeforeAnythingIsStaged(): void
    {
        $tester = $this->execute(['os' => 'linux', '--skip-composer' => true], ['prebuild' => ['exit 7']]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->project.'/nativephp/build/app');
        self::assertSame([], $this->calls());
    }

    public function testAFailingPackagerIsReportedAsAFailure(): void
    {
        (new Filesystem())->dumpFile($this->stubs.'/npm', "#!/bin/sh\nexit 9\n");
        (new Filesystem())->chmod($this->stubs.'/npm', 0o755);

        $tester = $this->execute(['os' => 'linux', '--skip-composer' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('npm failed (exit 9)', $this->flatten($tester));
    }

    public function testAnUnknownArchitectureIsRefusedBeforeAnyWork(): void
    {
        $tester = $this->execute(['os' => 'linux', 'arch' => 'riscv']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->project.'/nativephp/build/app');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     */
    private function execute(array $input, array $config = []): CommandTester
    {
        $tester = new CommandTester(new BuildCommand($this->project, $this->config($config)));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return [
            'name' => 'Desk Pad',
            'app_id' => 'com.acme.deskpad',
            'version' => '2.3.4',
            'author' => 'Acme',
            'build' => [
                'exclude' => ['.stubs', 'nativephp', 'vendor'],
                'keep' => ['var/cache', 'var/log'],
                'env_defaults' => ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'],
                'env_remove' => ['STRIPE_*'],
                'env_keep' => ['APP_SECRET'],
            ],
            ...$overrides,
        ];
    }

    /** A stand-in for what `native:install` puts in the project. */
    private function installElectronProject(): void
    {
        $scripts = [
            'build' => 'electron-vite build',
            'build:linux-x64' => 'x', 'build:linux-arm64' => 'x',
            'build:mac-x64' => 'x', 'build:mac-arm64' => 'x',
            'build:win-x64' => 'x',
            'publish:linux-x64' => 'x', 'publish:linux-arm64' => 'x',
            'publish:mac-x64' => 'x', 'publish:mac-arm64' => 'x',
            'publish:win-x64' => 'x',
        ];

        $fs = new Filesystem();

        $fs->dumpFile(
            $this->project.'/nativephp/electron/package.json',
            json_encode(['name' => 'nativephp', 'version' => '1.0.0', 'scripts' => $scripts], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // native:install leaves the runtime's default icons here: build/icon.png comes
        // from resources/electron, the two tray templates from resources/build.
        $fs->dumpFile($this->project.'/nativephp/electron/build/icon.png', 'DEFAULT');
        $fs->dumpFile($this->project.'/nativephp/electron/build/IconTemplate.png', 'DEFAULT');
        $fs->dumpFile($this->project.'/nativephp/electron/build/IconTemplate@2x.png', 'DEFAULT');
    }

    /** @return array<string, string> */
    private function electronScripts(): array
    {
        /** @var array{scripts: array<string, string>} $json */
        $json = json_decode((string) file_get_contents($this->project.'/nativephp/electron/package.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $json['scripts'];
    }

    private function installStubs(): void
    {
        $fs = new Filesystem();

        foreach (['npm', 'npx'] as $binary) {
            $fs->dumpFile($this->stubs.'/'.$binary, sprintf(
                "#!/bin/sh\necho \"%s $*\" >> %s\nenv | grep -E '^(NATIVEPHP_|APP_)' | sed 's/^/env /' >> %s\nexit 0\n",
                $binary,
                $this->log,
                $this->log,
            ));
            $fs->chmod($this->stubs.'/'.$binary, 0o755);
        }
    }

    /** @return list<string> */
    private function calls(): array
    {
        if (!is_file($this->log)) {
            return [];
        }

        $lines = array_filter(
            explode("\n", (string) file_get_contents($this->log)),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, 'env '),
        );

        return array_values(array_map('trim', $lines));
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $env = [];

        foreach (explode("\n", (string) @file_get_contents($this->log)) as $line) {
            if (str_starts_with($line, 'env ') && str_contains($line, '=')) {
                [$name, $value] = explode('=', substr($line, 4), 2);
                $env[$name] = $value;
            }
        }

        return $env;
    }

    private function setPath(string $path): void
    {
        putenv('PATH='.$path);
        $_SERVER['PATH'] = $path;
        $_ENV['PATH'] = $path;
    }

    private function flatten(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
