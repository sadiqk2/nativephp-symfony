<?php

declare(strict_types=1);

namespace Native\Symfony\Runtime;

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
 * Idempotent — re-running detects already-applied hunks.
 */
final class RuntimePatcher
{
    public function __construct(
        private readonly string $cli = 'bin/console',
        private readonly string $router = 'nativephp-router.php',
        private readonly string $devEnv = 'dev',
        private readonly string $prodEnv = 'prod',
        private readonly string $scheduleCommand = 'native:schedule-tick',
    ) {
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

        return [
            ...$this->patchFile($server.'/php.ts', $this->phpTsHunks()),
            ...$this->patchFile($server.'/api/childProcess.ts', $this->childProcessHunks()),
        ];
    }

    /**
     * @param list<array{name: string, from: string, to: string, applied: string, strict: bool}> $hunks
     *
     * @return list<string>
     */
    private function patchFile(string $path, array $hunks): array
    {
        if (!is_file($path)) {
            throw PatchFailed::missingFile($path);
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
        }

        if ($text !== $original) {
            file_put_contents($path, $text);
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
                'name' => "APP_ENV: local/production → {$this->devEnv}/{$this->prodEnv}",
                'from' => "APP_ENV: process.env.NODE_ENV === 'development' ? 'local' : 'production',",
                'to' => "APP_ENV: process.env.NODE_ENV === 'development' ? '{$this->devEnv}' : '{$this->prodEnv}',",
                'applied' => "? '{$this->devEnv}' : '{$this->prodEnv}',",
                'strict' => true,
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
