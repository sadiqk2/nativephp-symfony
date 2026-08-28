<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Command\RunCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `native:run`, with npx replaced by a recorder.
 *
 * The command's real work happens before Electron starts: it assembles
 * $NATIVEPHP_BUILD_PATH, which needs an icon, a CA bundle and a PHP binary, and then
 * hands the runtime an environment it reads for all three. None of that was covered —
 * and the CA bundle search in particular exists because the single Debian path it used
 * to have made the command hard-fail on macOS, which is where most Electron
 * development happens.
 */
final class RunCommandTest extends TestCase
{
    private string $project;
    private string $stubs;
    private string $log;
    private string $path;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/np-symfony-run-'.bin2hex(random_bytes(4));
        $this->stubs = $this->project.'/.stubs';
        $this->log = $this->stubs.'/calls.log';

        $fs = new Filesystem();
        $fs->dumpFile($this->project.'/nativephp/electron/package.json', '{"name":"nativephp"}');
        $fs->mkdir($this->project.'/nativephp/electron/node_modules');
        $fs->dumpFile($this->project.'/nativephp/electron/build/icon.png', 'png');
        $fs->dumpFile($this->project.'/nativephp/electron/build/IconTemplate.png', 'tray');
        $fs->dumpFile($this->project.'/nativephp/electron/build/IconTemplate@2x.png', 'tray2x');
        $fs->dumpFile($this->project.'/vendor/nativephp/php-bin/cacert.pem', 'CERT');

        $fs->dumpFile($this->stubs.'/npx', sprintf(
            "#!/bin/sh\necho \"npx $*\" >> %s\nenv | grep -E '^(NATIVEPHP_|APP_PATH|NODE_ENV)' | sed 's/^/env /' >> %s\nexit 0\n",
            $this->log,
            $this->log,
        ));
        $fs->chmod($this->stubs.'/npx', 0o755);

        $this->path = (string) getenv('PATH');
        $this->setPath($this->stubs.':'.$this->path);
    }

    protected function tearDown(): void
    {
        $this->setPath($this->path);
        (new Filesystem())->remove($this->project);
    }

    public function testItAssemblesTheBuildPathAndStartsElectron(): void
    {
        $tester = $this->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains('npx electron-vite dev', $this->calls());

        $build = $this->project.'/nativephp/build';

        self::assertFileExists($build.'/icon.png');
        // MenuBar::create() defaults its tray image to state.icon with icon.png swapped
        // for IconTemplate.png, and Electron refuses a Tray image it cannot load — so
        // the templates belong in the build path just as much as the icon does.
        self::assertSame('tray', file_get_contents($build.'/IconTemplate.png'));
        self::assertSame('tray2x', file_get_contents($build.'/IconTemplate@2x.png'));
        self::assertSame('CERT', file_get_contents($build.'/cacert.pem'));
        self::assertFileExists($build.'/php/php');
        self::assertTrue(is_executable($build.'/php/php'));
    }

    public function testTheRuntimeIsToldWhereEverythingIs(): void
    {
        $this->execute([]);

        $env = $this->environment();

        self::assertSame($this->project, $env['APP_PATH']);
        self::assertSame($this->project.'/nativephp/build', $env['NATIVEPHP_BUILD_PATH']);
        self::assertSame($this->project.'/nativephp/electron', $env['NATIVEPHP_ELECTRON_PATH']);
        self::assertSame('false', $env['NATIVEPHP_BUILDING']);
        self::assertSame('development', $env['NODE_ENV']);
        // The runtime appends the binary name to this, so the trailing separator is
        // part of the contract rather than cosmetic.
        self::assertStringEndsWith('/', $env['NATIVEPHP_PHP_BINARY_PATH']);
        self::assertSame(\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION, $env['NATIVEPHP_PHP_BINARY_VERSION']);
        self::assertArrayNotHasKey('NATIVEPHP_NO_FOCUS', $env);
    }

    public function testNoFocusIsPassedThrough(): void
    {
        $this->execute(['--no-focus' => true]);

        self::assertSame('1', $this->environment()['NATIVEPHP_NO_FOCUS'] ?? null);
    }

    public function testAMissingElectronProjectIsRefusedWithInstructions(): void
    {
        (new Filesystem())->remove($this->project.'/nativephp/electron/package.json');

        $tester = $this->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('native:install', $this->flatten($tester));
        self::assertSame([], $this->calls());
    }

    public function testAMissingNodeModulesIsRefusedWithInstructions(): void
    {
        (new Filesystem())->remove($this->project.'/nativephp/electron/node_modules');

        $tester = $this->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--skip-npm', $this->flatten($tester));
    }

    public function testWithoutPhpBinItFallsBackToAPlatformCaBundle(): void
    {
        // php-bin's bundle is preferred, because it is the one the packaged app will
        // use. Without it the command searches the ini settings and then the platform's
        // own locations — the fallback that exists because the single Debian path it
        // started with made `native:run` hard-fail on macOS and Windows.
        (new Filesystem())->remove($this->project.'/vendor');

        $tester = $this->execute([]);

        $copied = $this->project.'/nativephp/build/cacert.pem';

        if (Command::FAILURE === $tester->getStatusCode()) {
            // A host with no CA bundle anywhere must say what to install rather than
            // starting Electron with outbound TLS that will fail later.
            self::assertStringContainsString('nativephp/php-bin', $this->flatten($tester));
            self::assertSame([], $this->calls());
            self::assertFileDoesNotExist($copied);

            return;
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($copied);
        self::assertNotSame('CERT', file_get_contents($copied), 'php-bin was removed, so the bundle must have come from the platform.');
        self::assertSame(
            file_get_contents($this->firstPlatformCaBundle()),
            file_get_contents($copied),
        );
    }

    public function testAnExistingBuildPathIsNotOverwritten(): void
    {
        $build = $this->project.'/nativephp/build';

        (new Filesystem())->dumpFile($build.'/icon.png', 'mine');
        (new Filesystem())->dumpFile($build.'/cacert.pem', 'mine');

        $this->execute([]);

        self::assertSame('mine', file_get_contents($build.'/icon.png'));
        self::assertSame('mine', file_get_contents($build.'/cacert.pem'));
    }

    public function testAMissingIconIsReportedRatherThanLeftToElectron(): void
    {
        (new Filesystem())->remove($this->project.'/nativephp/electron/build');

        $tester = $this->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('icon.png', $this->flatten($tester));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function firstPlatformCaBundle(): string
    {
        $candidates = [
            ini_get('openssl.cafile') ?: null,
            ini_get('curl.cainfo') ?: null,
            ...match (\PHP_OS_FAMILY) {
                'Darwin' => ['/etc/ssl/cert.pem', '/opt/homebrew/etc/ca-certificates/cert.pem', '/usr/local/etc/openssl@3/cert.pem'],
                'Windows' => [],
                default => ['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'],
            },
        ];

        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && '' !== $candidate && is_file($candidate)) {
                return $candidate;
            }
        }

        self::fail('The command found a CA bundle this test cannot name.');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $env
     */
    private function execute(array $input, array $env = []): CommandTester
    {
        $restore = [];

        foreach ($env as $name => $value) {
            $restore[$name] = getenv($name);
            putenv($name.'='.$value);
        }

        try {
            $tester = new CommandTester(new RunCommand($this->project));
            $tester->execute($input);

            return $tester;
        } finally {
            foreach ($restore as $name => $value) {
                false === $value ? putenv($name) : putenv($name.'='.$value);
            }
        }
    }

    /** @return list<string> */
    private function calls(): array
    {
        if (!is_file($this->log)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($this->log))),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, 'env '),
        ));
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
