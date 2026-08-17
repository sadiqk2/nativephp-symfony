<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Runtime;

use Native\Symfony\Desktop\Manifest\ManifestSupport;
use Native\Symfony\Desktop\Manifest\ManifestSupportDetector;

/**
 * Rewrites the eight places NativePHP's Electron runtime assumes Laravel.
 *
 * This exists because those eight values are hardcoded string literals in the
 * runtime's TypeScript rather than configuration. The upstream fix is a manifest
 * file declaring them (see ANALYSIS.md §6); until that lands, an adapter patches
 * its own copy.
 *
 * Patching a copy is legitimate rather than a hack: `native:install --publish`
 * mirrors the whole Electron project into the application's own
 * nativephp/electron/ directory, and the runtime prefers that copy over the
 * vendored one whenever a package.json exists there. Upstream is untouched.
 *
 * A runtime that reads `nativephp.json` needs none of this, and patching it would
 * be worse than useless — the strict hunks no longer match, so it would abort an
 * install that had nothing wrong with it. Such a runtime is detected and left
 * alone; see ManifestSupport for why an inconclusive detection still patches.
 *
 * Idempotent — re-running detects already-applied hunks.
 */
final class RuntimePatcher
{
    private readonly ManifestSupportDetector $detector;

    public function __construct(
        private readonly string $cli = 'bin/console',
        private readonly string $router = 'nativephp-router.php',
        private readonly string $devEnv = 'dev',
        private readonly string $prodEnv = 'prod',
        private readonly string $scheduleCommand = 'native:schedule-tick',
        ?ManifestSupportDetector $detector = null,
    ) {
        // Defaulted rather than required: the detector is a pure filesystem sniff
        // with no collaborators, and every existing caller constructs this class
        // with nothing but strings.
        $this->detector = $detector ?? new ManifestSupportDetector();
    }

    /**
     * @return list<string> Human-readable description of what changed
     *
     * @throws PatchFailed when a hunk's target has changed upstream
     */
    public function patch(string $electronProjectPath): array
    {
        $server = rtrim($electronProjectPath, '/').'/electron-plugin/src/server';

        if (!is_dir($server)) {
            throw PatchFailed::notARuntime($electronProjectPath);
        }

        if (ManifestSupport::Supported === $this->detector->detect($electronProjectPath)) {
            // Nothing to *rewrite* — but the app now must ship the manifest, because
            // this runtime resolves Laravel's paths whenever it cannot find one. The
            // bug fixes below still apply: a manifest-aware runtime is not a fixed
            // one, and they are orthogonal to how it resolves paths.
            return [
                'Runtime reads nativephp.json; skipped the Laravel-ism patches.',
                'Run "bin/console native:manifest" to write it — without it this runtime uses Laravel\'s paths.',
                ...$this->patchBugFixes($electronProjectPath),
            ];
        }

        return [
            ...$this->patchFile($server.'/php.ts', $this->phpTsHunks()),
            ...$this->patchFile($server.'/api/childProcess.ts', $this->childProcessHunks()),
            ...$this->patchBugFixes($electronProjectPath),
        ];
    }

    /**
     * Fixes for runtime bugs that bite Symfony apps, carried locally.
     *
     * These are not Laravel-isms — they are defects, four of them filed upstream as
     * NativePHP/desktop #137–#140 and the fifth found here. Upstream has not
     * answered discussion #504 in eighteen months, so treating a merge as the
     * delivery mechanism would mean shipping known-broken behaviour indefinitely.
     * `native:install --publish` already gives every app its own copy of the
     * runtime, so the fixes can simply be part of what this bundle installs.
     *
     * Every hunk here is non-strict and every file optional, which is the opposite
     * of the policy for the Laravel-isms above. A missing target means the runtime
     * has moved on — most likely because the fix landed upstream — and aborting an
     * install over a bug that is no longer there would be absurd.
     *
     * @return list<string>
     */
    private function patchBugFixes(string $electronProjectPath): array
    {
        $plugin = rtrim($electronProjectPath, '/').'/electron-plugin/src';

        return [
            ...$this->patchFile($plugin.'/server/api/window.ts', $this->windowHunks(), required: false),
            ...$this->patchFile($plugin.'/server/api/shell.ts', $this->shellHunks(), required: false),
            ...$this->patchFile($plugin.'/server/utils.ts', $this->utilsHunks(), required: false),
            ...$this->patchFile($plugin.'/preload/index.mts', $this->preloadHunks(), required: false),
        ];
    }

    /**
     * @param list<array{name: string, from: string, to: string, applied: string, strict: bool}> $hunks
     *
     * @return list<string>
     */
    private function patchFile(string $path, array $hunks, bool $required = true): array
    {
        if (!is_file($path)) {
            if ($required) {
                throw PatchFailed::missingFile($path);
            }

            return [sprintf('%s is absent; skipped its fixes.', basename($path))];
        }

        $original = (string) file_get_contents($path);
        $text = $original;
        $applied = [];

        foreach ($hunks as $hunk) {
            if (str_contains($text, $hunk['from'])) {
                $count = substr_count($text, $hunk['from']);
                $text = str_replace($hunk['from'], $hunk['to'], $text);
                $applied[] = 1 === $count
                    ? $hunk['name']
                    : sprintf('%s (%d sites)', $hunk['name'], $count);

                continue;
            }

            if (str_contains($text, $hunk['applied'])) {
                $applied[] = $hunk['name'].' — already applied';

                continue;
            }

            if ($hunk['strict']) {
                throw PatchFailed::hunkDidNotMatch($hunk['name'], $path);
            }

            // A bug fix whose target is gone is worth saying out loud rather than
            // passing over in silence: it usually means the fix landed upstream, and
            // that is exactly when someone wants to know this hunk can be dropped.
            if (isset($hunk['onMiss'])) {
                $applied[] = $hunk['name'].' — '.$hunk['onMiss'];
            }
        }

        if ($text !== $original && false === @file_put_contents($path, $text)) {
            throw PatchFailed::writeFailed($path);
        }

        return $applied;
    }

    /** @return list<array{name: string, from: string, to: string, applied: string, strict: bool}> */
    private function phpTsHunks(): array
    {
        return [
            [
                'name' => "CLI entrypoint: ['artisan', …] → ['{$this->cli}', …]",
                'from' => "['artisan',",
                'to' => "['{$this->cli}',",
                'applied' => "['{$this->cli}',",
                'strict' => true,
            ],
            [
                'name' => "secure-bundle guard: argv[0] === '{$this->cli}'",
                'from' => "args[0] === 'artisan'",
                'to' => "args[0] === '{$this->cli}'",
                'applied' => "args[0] === '{$this->cli}'",
                'strict' => false,
            ],
            [
                // The runtime spawns this every 60s with no check that it
                // exists. Pointing it at a command we ship avoids both the
                // per-minute console error and any clash with an app that has
                // its own schedule:run (symfony/scheduler defines one).
                'name' => "scheduler tick: schedule:run → {$this->scheduleCommand}",
                'from' => "'{$this->cli}', 'schedule:run'",
                'to' => "'{$this->cli}', '{$this->scheduleCommand}'",
                'applied' => "'{$this->scheduleCommand}'",
                'strict' => false,
            ],
            [
                'name' => "router script → public/{$this->router}",
                'from' => "'vendor',\n            'laravel',\n            'framework',\n            'src',\n            'Illuminate',\n            'Foundation',\n            'resources',\n            'server.php',",
                'to' => "'public',\n            '{$this->router}',",
                // Marker is the bare filename: an earlier tool (or a hand edit)
                // may have collapsed the join() onto one line, and refusing to
                // recognise that would abort an install that is already correct.
                'applied' => $this->router,
                'strict' => true,
            ],
            [
                'name' => 'guard the storage/ copy (a missing storage/ otherwise kills the boot)',
                'from' => "        copySync(join(appPath, 'storage'), storagePath);",
                'to' => "        const appStorage = join(appPath, 'storage');\n".
                    "        if (existsSync(appStorage)) {\n".
                    "            copySync(appStorage, storagePath);\n".
                    "        } else {\n".
                    "            console.log('No storage/ dir in the app; skipping copy.');\n".
                    '        }',
                'applied' => 'No storage/ dir in the app',
                'strict' => true,
            ],
            [
                // Both run only in a packaged app, and neither command exists in
                // Symfony, so each boot logged a stack trace and a "Failed to cache
                // view and routes". Worse, the runtime only records its
                // optimized_version when the call succeeds — so it never did, and
                // retried on every launch forever. Synthesising the success is what
                // the manifest does too: patch 0001 lets an app declare these null
                // and skip them.
                'name' => 'skip Laravel\'s optimize (no such command in Symfony)',
                'from' => "const result = callPhpSync(['{$this->cli}', 'optimize'], phpOptions, phpIniSettings);",
                'to' => "// Symfony has no `optimize`; the build ships a warmed prod cache instead.\n".
                    '        const result = { status: 0, stderr: Buffer.from(\'\') };',
                'applied' => 'Symfony has no `optimize`',
                'strict' => false,
                'onMiss' => 'target not found; the runtime may already skip it',
            ],
            [
                'name' => 'skip Laravel\'s migrate (Doctrine is not artisan)',
                'from' => "const result = callPhpSync(['{$this->cli}', 'migrate', '--force'], phpOptions, phpIniSettings);",
                'to' => "// Doctrine migrations are the app's business, not the runtime's: it cannot\n".
                    "        // know whether this app has them, and `migrate --force` is not a Symfony\n".
                    "        // command. Run them from your own bootstrapper if you need them.\n".
                    '        const result = { status: 0, stderr: Buffer.from(\'\') };',
                'applied' => "Doctrine migrations are the app's business",
                'strict' => false,
                'onMiss' => 'target not found; the runtime may already skip it',
            ],
            [
                'name' => "APP_ENV: local/production → {$this->devEnv}/{$this->prodEnv}",
                'from' => "APP_ENV: process.env.NODE_ENV === 'development' ? 'local' : 'production',",
                'to' => "APP_ENV: process.env.NODE_ENV === 'development' ? '{$this->devEnv}' : '{$this->prodEnv}',",
                'applied' => "? '{$this->devEnv}' : '{$this->prodEnv}',",
                'strict' => true,
            ],
        ];
    }

    /**
     * NativePHP/desktop #138 and #137.
     *
     * @return list<array{name: string, from: string, to: string, applied: string, strict: bool, onMiss?: string}>
     */
    private function windowHunks(): array
    {
        return [
            [
                // getFocusedWindow() returns null whenever the app is in the
                // background, which any PHP process can hit — a Messenger worker
                // calling Window::current(), for instance. Dereferencing it threw a
                // bare string out of getWindowData().
                'name' => 'window/current: 404 instead of dereferencing a null focused window (#138)',
                'from' => "router.get('/current', (req, res) => {\n".
                    "    // Find the current window object\n".
                    "    const currentWindow = Object.values(state.windows).find(\n".
                    "        (window) => window.id === BrowserWindow.getFocusedWindow().id,\n".
                    '    );',
                'to' => "router.get('/current', (req, res) => {\n".
                    "    const focused = BrowserWindow.getFocusedWindow();\n\n".
                    "    // getFocusedWindow() returns null whenever the app is in the background, which\n".
                    "    // any PHP process can hit. Dereferencing it threw a bare string from getWindowData().\n".
                    "    if (!focused) {\n".
                    "        res.sendStatus(404);\n".
                    "        return;\n".
                    "    }\n\n".
                    "    // Find the current window object\n".
                    '    const currentWindow = Object.values(state.windows).find((window) => window.id === focused.id);',
                'applied' => 'const focused = BrowserWindow.getFocusedWindow();',
                'strict' => false,
                'onMiss' => 'target not found; assuming it is fixed upstream',
            ],
            [
                // parseFloat(undefined) is NaN and setZoomFactor(NaN) renders the
                // page at an absurd zoom. Laravel's Window always serialises a
                // default, so this never surfaced there; every other client hits it
                // on the first window it opens.
                'name' => 'window/open: default the zoom factor instead of passing NaN (#137)',
                'from' => "    window.webContents.on('dom-ready', () => {\n".
                    '        window.webContents.setZoomFactor(parseFloat(zoomFactor));',
                'to' => "    window.webContents.on('dom-ready', () => {\n".
                    "        // parseFloat(undefined) is NaN, and setZoomFactor(NaN) renders the page at an\n".
                    "        // absurd zoom.\n".
                    "        const zoom = parseFloat(zoomFactor);\n\n".
                    '        window.webContents.setZoomFactor(Number.isFinite(zoom) && zoom > 0 ? zoom : 1);',
                'applied' => 'Number.isFinite(zoom) && zoom > 0 ? zoom : 1',
                'strict' => false,
                'onMiss' => 'target not found; assuming it is fixed upstream',
            ],
            [
                // Deliberately beyond #137, which only fixes /open: the same hazard
                // is on this endpoint too. Our own WindowManager types the argument
                // float and PendingWindow defaults it to 1.0, so the bundle cannot
                // send NaN — but ClientInterface is a documented escape hatch, and
                // anything reaching this endpoint through it can.
                'name' => 'window/set-zoom-factor: default the zoom factor instead of passing NaN',
                'from' => "    state.windows[id]?.webContents.setZoomFactor(parseFloat(zoomFactor));",
                'to' => "    const requestedZoom = parseFloat(zoomFactor);\n\n".
                    '    state.windows[id]?.webContents.setZoomFactor('.
                    'Number.isFinite(requestedZoom) && requestedZoom > 0 ? requestedZoom : 1);',
                'applied' => 'const requestedZoom = parseFloat(zoomFactor);',
                'strict' => false,
                'onMiss' => 'target not found; assuming it is fixed upstream',
            ],
        ];
    }

    /**
     * NativePHP/desktop #139.
     *
     * @return list<array{name: string, from: string, to: string, applied: string, strict: bool, onMiss?: string}>
     */
    private function shellHunks(): array
    {
        return [
            [
                'name' => 'shell/trash-item: send a body with the 400 (#139)',
                'from' => "    } catch {\n".
                    '        res.status(400).json();',
                'to' => "    } catch (e) {\n".
                    "        // res.json() with no argument is rejected by express, turning a handled\n".
                    "        // failure into an unhandled one.\n".
                    '        res.status(400).json({ error: e instanceof Error ? e.message : String(e) });',
                'applied' => 'res.status(400).json({ error:',
                'strict' => false,
                'onMiss' => 'target not found; assuming it is fixed upstream',
            ],
        ];
    }

    /**
     * NativePHP/desktop #140 — the single most useful of these for a Symfony app.
     *
     * The runtime swallowing every failed callback is why the documented symptom of
     * almost everything going wrong is "the window opens and nothing happens": a
     * crashed, 500ing or 403ing PHP app is indistinguishable from a healthy one.
     *
     * @return list<array{name: string, from: string, to: string, applied: string, strict: bool, onMiss?: string}>
     */
    private function utilsHunks(): array
    {
        return [
            [
                'name' => 'notifyLaravel: report failures under SHELL_VERBOSITY instead of swallowing them (#140)',
                'from' => "    } catch {\n".
                    "        //\n".
                    '    }',
                'to' => "    } catch (e) {\n".
                    "        // Previously swallowed entirely, which made a crashed, 500ing or 403ing PHP\n".
                    "        // app indistinguishable from a healthy one. Behind SHELL_VERBOSITY so normal\n".
                    "        // runs stay quiet.\n".
                    "        if (parseInt(process.env.SHELL_VERBOSITY) > 0) {\n".
                    "            console.error(`notifyLaravel('\${endpoint}') failed:`, e instanceof Error ? e.message : e);\n".
                    "        }\n".
                    '    }',
                'applied' => "console.error(`notifyLaravel(",
                'strict' => false,
                'onMiss' => 'target not found; assuming it is fixed upstream',
            ],
        ];
    }

    /**
     * Found while building the demo, and not filed upstream.
     *
     * `Native.on` registers a *fresh* ipcRenderer listener per subscription, so N
     * subscriptions mean N listeners each doing a string comparison on every event.
     * Past ten, Node prints a MaxListenersExceededWarning that looks exactly like a
     * leak — the demo trips it with eleven, which is not an unusual number for an
     * app that logs runtime events. One dispatcher and a map of handlers is the same
     * behaviour without the warning or the O(N) walk.
     *
     * @return list<array{name: string, from: string, to: string, applied: string, strict: bool, onMiss?: string}>
     */
    private function preloadHunks(): array
    {
        return [
            [
                'name' => 'preload: dispatch native events from one listener, not one per subscription',
                'from' => "const Native = {\n".
                    "    on: (event, callback) => {\n".
                    "        ipcRenderer.on('native-event', (_, data) => {\n".
                    "            // Strip leading slashes\n".
                    "            event = event.replace(/^(\\\\)+/, '');\n".
                    "            data.event = data.event.replace(/^(\\\\)+/, '');\n\n".
                    "            if (event === data.event) {\n".
                    "                return callback(data.payload, event);\n".
                    "            }\n".
                    '        });',
                'to' => "// One listener for every subscription: registering one per Native.on() call trips\n".
                    "// Node's MaxListenersExceededWarning at eleven subscriptions, and walks them all on\n".
                    "// every event.\n".
                    "const nativeHandlers = new Map();\n\n".
                    "ipcRenderer.on('native-event', (_, data) => {\n".
                    "    const name = String(data.event).replace(/^(\\\\)+/, '');\n\n".
                    "    for (const callback of nativeHandlers.get(name) ?? []) {\n".
                    "        callback(data.payload, name);\n".
                    "    }\n".
                    "});\n\n".
                    "const Native = {\n".
                    "    on: (event, callback) => {\n".
                    "        const name = String(event).replace(/^(\\\\)+/, '');\n\n".
                    "        nativeHandlers.set(name, [...(nativeHandlers.get(name) ?? []), callback]);",
                'applied' => 'const nativeHandlers = new Map();',
                'strict' => false,
                'onMiss' => 'target not found; assuming it is fixed upstream',
            ],
        ];
    }

    /** @return list<array{name: string, from: string, to: string, applied: string, strict: bool}> */
    private function childProcessHunks(): array
    {
        return [
            [
                'name' => "childProcess CLI guard: cmd[0] === '{$this->cli}'",
                'from' => "settings.cmd[0] === 'artisan'",
                'to' => "settings.cmd[0] === '{$this->cli}'",
                'applied' => "settings.cmd[0] === '{$this->cli}'",
                'strict' => false,
            ],
        ];
    }
}
