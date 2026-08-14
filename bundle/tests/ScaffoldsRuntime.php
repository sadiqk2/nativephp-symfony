<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A minimal copy of the upstream Electron runtime, as the patcher expects to find it.
 *
 * Shared between the patcher's own tests and the installer's, because the installer
 * patches on the way through: a fixture that drifts from upstream would make one of the
 * two pass for the wrong reason.
 */
trait ScaffoldsRuntime
{
    private function serverDir(): string
    {
        return $this->runtimeRoot().'/electron-plugin/src/server';
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
