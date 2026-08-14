<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Runtime\PatchFailed;
use Native\Symfony\Runtime\RuntimePatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RuntimePatcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/np-patcher-'.bin2hex(random_bytes(6));
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testItAppliesEveryHunk(): void
    {
        $applied = (new RuntimePatcher())->patch($this->root);

        self::assertCount(7, $applied);

        $php = $this->phpTs();
        self::assertStringContainsString("['bin/console', 'native:config']", $php);
        self::assertStringNotContainsString("['artisan',", $php);
        self::assertStringContainsString("'nativephp-router.php',", $php);
        self::assertStringNotContainsString("'laravel',", $php);
        self::assertStringContainsString('existsSync(appStorage)', $php);
        self::assertStringContainsString("? 'dev' : 'prod'", $php);
        self::assertStringContainsString("'bin/console', 'native:schedule-tick'", $php);
        self::assertStringNotContainsString("'schedule:run'", $php);
        self::assertStringContainsString("settings.cmd[0] === 'bin/console'", $this->childProcessTs());
    }

    public function testItIsIdempotent(): void
    {
        $patcher = new RuntimePatcher();
        $patcher->patch($this->root);
        $afterFirst = $this->phpTs();

        $second = $patcher->patch($this->root);

        self::assertSame($afterFirst, $this->phpTs(), 'A second run must not change the file again.');
        foreach ($second as $line) {
            self::assertStringContainsString('already applied', $line);
        }
    }

    public function testItHonoursACustomCliAndRouter(): void
    {
        (new RuntimePatcher(cli: 'app/console', router: 'router.php', devEnv: 'local', prodEnv: 'production'))
            ->patch($this->root);

        $php = $this->phpTs();
        self::assertStringContainsString("['app/console', 'native:config']", $php);
        self::assertStringContainsString("'router.php',", $php);
        self::assertStringContainsString("? 'local' : 'production'", $php);
    }

    public function testItRefusesADirectoryThatIsNotARuntime(): void
    {
        $this->expectException(PatchFailed::class);
        $this->expectExceptionMessageMatches('/does not look like a NativePHP Electron project/');

        (new RuntimePatcher())->patch($this->root.'/nope');
    }

    public function testItFailsLoudlyWhenAHunkNoLongerMatches(): void
    {
        // Upstream drift must stop the install, not silently skip a hunk — the
        // app would boot into a Laravel router path and 404 everything.
        file_put_contents($this->serverDir().'/php.ts', "const x = 1;\n");

        $this->expectException(PatchFailed::class);
        $this->expectExceptionMessageMatches('/has changed upstream/');

        (new RuntimePatcher())->patch($this->root);
    }

    private function serverDir(): string
    {
        return $this->root.'/electron-plugin/src/server';
    }

    private function phpTs(): string
    {
        return (string) file_get_contents($this->serverDir().'/php.ts');
    }

    private function childProcessTs(): string
    {
        return (string) file_get_contents($this->serverDir().'/api/childProcess.ts');
    }

    /**
     * Fixtures reproduce the exact upstream text the patcher targets, including
     * indentation — the router hunk is whitespace-sensitive.
     */
    private function scaffold(): void
    {
        $fs = new Filesystem();
        $fs->mkdir($this->serverDir().'/api');

        $fs->dumpFile($this->serverDir().'/php.ts', <<<'TS'
        async function retrievePhpIniSettings() {
            const command = ['artisan', 'native:php-ini'];
            return await promisify(execFile)(state.php, command, phpOptions);
        }

        async function retrieveNativePHPConfig() {
            const command = ['artisan', 'native:config'];
            return await promisify(execFile)(state.php, command, phpOptions);
        }

        function callPhp(args, options, phpIniSettings = {}) {
            if (args[0] === 'artisan' && runningSecureBuild()) {
                args.unshift(join(getAppPath(), 'build', '__nativephp_app_bundle'));
            }
            return spawn(state.php, args, {});
        }

        function ensureAppFoldersAreAvailable() {
            if (!existsSync(storagePath) || process.env.NODE_ENV === 'development') {
                const appPath = getAppPath();
                copySync(join(appPath, 'storage'), storagePath);
            }
        }

        function startScheduler(secret, apiPort, phpIniSettings = {}) {
            return callPhp(['artisan', 'schedule:run'], phpOptions, phpIniSettings);
        }

        function getDefaultEnvironmentVariables(secret?: string, apiPort?: number): EnvironmentVariables {
            const variables: EnvironmentVariables = {
                APP_ENV: process.env.NODE_ENV === 'development' ? 'local' : 'production',
                NATIVEPHP_RUNNING: 'true',
            };
            return variables;
        }

        async function serveApp(secret, apiPort, phpIniSettings): Promise<ProcessResult> {
            const result = callPhpSync(['artisan', 'optimize'], phpOptions, phpIniSettings);
            const migrate = callPhpSync(['artisan', 'migrate', '--force'], phpOptions, phpIniSettings);

            if (runningSecureBuild()) {
                serverPath = join(appPath, 'build', '__nativephp_app_bundle');
            } else {
                console.log('* * * Running from source * * *');
                serverPath = join(
                    appPath,
                    'vendor',
                    'laravel',
                    'framework',
                    'src',
                    'Illuminate',
                    'Foundation',
                    'resources',
                    'server.php',
                );
                cwd = join(appPath, 'public');
            }
        }
        TS);

        $fs->dumpFile($this->serverDir().'/api/childProcess.ts', <<<'TS'
        function startPhpProcess(settings) {
            if (settings.cmd[0] === 'artisan' && runningSecureBuild()) {
                settings.cmd.unshift(join(getAppPath(), 'build', '__nativephp_app_bundle'));
            }
        }
        TS);
    }
}
