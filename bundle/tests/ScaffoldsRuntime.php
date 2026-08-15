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

        $this->scaffoldBugFixTargets($fs);
    }

    /**
     * The four files the bug-fix hunks target, again quoted verbatim from upstream.
     *
     * These carry defects rather than Laravel-isms, so the bundle patches them on the
     * way in instead of waiting for NativePHP/desktop #137–#140 to be reviewed.
     */
    private function scaffoldBugFixTargets(Filesystem $fs): void
    {
        $fs->dumpFile($this->serverDir().'/api/window.ts', <<<'TS'
        router.get('/current', (req, res) => {
            // Find the current window object
            const currentWindow = Object.values(state.windows).find(
                (window) => window.id === BrowserWindow.getFocusedWindow().id,
            );

            // Get the developer-assigned id for that window
            const id = Object.keys(state.windows).find((key) => state.windows[key] === currentWindow);

            res.json(getWindowData(id));
        });

        router.post('/open', (req, res) => {
            const url = appendWindowIdToUrl(req.body.url, id);

            window.loadURL(url);

            window.webContents.on('dom-ready', () => {
                window.webContents.setZoomFactor(parseFloat(zoomFactor));
            });
        });
        TS);

        $fs->dumpFile($this->serverDir().'/api/shell.ts', <<<'TS'
        router.delete('/trash-item', async (req, res) => {
            try {
                await shell.trashItem(path);

                res.sendStatus(200);
            } catch {
                res.status(400).json();
            }
        });
        TS);

        $fs->dumpFile($this->serverDir().'/utils.ts', <<<'TS'
        export async function notifyLaravel(endpoint: string, payload = {}) {
            if (endpoint === 'events') {
                broadcastToWindows('native-event', payload);
            }

            try {
                await axios.post(`http://127.0.0.1:${state.phpPort}/_native/api/${endpoint}`, payload, {
                    headers: {
                        'X-NativePHP-Secret': state.randomSecret,
                    },
                });
            } catch {
                //
            }
        }
        TS);

        $fs->dumpFile($this->runtimeRoot().'/electron-plugin/src/preload/index.mts', <<<'TS'
        const Native = {
            on: (event, callback) => {
                ipcRenderer.on('native-event', (_, data) => {
                    // Strip leading slashes
                    event = event.replace(/^(\\)+/, '');
                    data.event = data.event.replace(/^(\\)+/, '');

                    if (event === data.event) {
                        return callback(data.payload, event);
                    }
                });
            },
            contextMenu: (template) => {
                const menu = remote.Menu.buildFromTemplate(template);
                menu.popup({ window: remote.getCurrentWindow() });
            },
        };
        TS);
    }
}
