<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Runtime\PatchFailed;
use Native\Symfony\Desktop\Runtime\RuntimePatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RuntimePatcherTest extends TestCase
{
    use ScaffoldsRuntime;

    private function runtimeRoot(): string
    {
        return $this->root;
    }

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

        // Nine Laravel-isms plus the six bug fixes.
        self::assertCount(15, $applied);

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

        // Neither command exists in Symfony. Unpatched, a packaged app logged a
        // stack trace for each on every launch — and because the runtime only
        // records optimized_version on success, it retried them forever.
        self::assertStringNotContainsString("'optimize'", $php);
        self::assertStringNotContainsString("'migrate', '--force'", $php);
        self::assertStringContainsString('Symfony has no `optimize`', $php);
        self::assertStringContainsString("Doctrine migrations are the app's business", $php);
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


    public function testItCarriesTheRuntimeBugFixes(): void
    {
        // Filed as NativePHP/desktop #137-#140 and unreviewed. `native:install
        // --publish` gives every app its own copy of the runtime, so waiting for a
        // merge would mean shipping known-broken behaviour for as long as upstream
        // stays quiet — which, on discussion #504, has been eighteen months.
        (new RuntimePatcher())->patch($this->root);

        $window = $this->read('server/api/window.ts');
        self::assertStringContainsString('const focused = BrowserWindow.getFocusedWindow();', $window);
        self::assertStringContainsString('res.sendStatus(404);', $window);
        self::assertStringNotContainsString('BrowserWindow.getFocusedWindow().id', $window);
        self::assertStringContainsString('Number.isFinite(zoom) && zoom > 0 ? zoom : 1', $window);
        // Both zoom endpoints, not just the one #137 covers: our own wrappers cannot
        // send NaN, but ClientInterface is a documented escape hatch that can.
        self::assertStringContainsString('const requestedZoom = parseFloat(zoomFactor);', $window);
        self::assertStringNotContainsString('setZoomFactor(parseFloat(zoomFactor))', $window);

        self::assertStringContainsString(
            'res.status(400).json({ error: e instanceof Error ? e.message : String(e) });',
            $this->read('server/api/shell.ts'),
        );

        // The most valuable of the five: without it a crashed, 500ing or 403ing PHP
        // app is indistinguishable from a healthy one.
        $utils = $this->read('server/utils.ts');
        self::assertStringContainsString('SHELL_VERBOSITY', $utils);
        self::assertStringNotContainsString("} catch {\n        //\n    }", $utils);

        // One ipcRenderer listener for every subscription, rather than one each.
        $preload = $this->read('preload/index.mts');
        self::assertStringContainsString('const nativeHandlers = new Map();', $preload);
        self::assertSame(1, substr_count($preload, "ipcRenderer.on('native-event'"));
    }

    public function testTheBugFixesStillApplyToAManifestAwareRuntime(): void
    {
        // A runtime that reads nativephp.json needs none of the Laravel-ism
        // rewrites, and patching it would abort an install that had nothing wrong
        // with it. It is not a *fixed* runtime, though, so the defects still apply.
        // Manifest support is a content sniff on php.ts; see ManifestSupportDetector.
        file_put_contents(
            $this->serverDir().'/php.ts',
            "const m = getManifest('nativephp.json');\n".file_get_contents($this->serverDir().'/php.ts'),
        );

        $applied = (new RuntimePatcher())->patch($this->root);

        self::assertStringContainsString('skipped the Laravel-ism patches', implode("\n", $applied));
        self::assertStringContainsString("['artisan',", $this->phpTs(), 'Laravel-isms must be left alone.');
        self::assertStringContainsString('SHELL_VERBOSITY', $this->read('server/utils.ts'));
    }

    public function testAMissingBugFixTargetIsReportedRatherThanFatal(): void
    {
        // The opposite policy to the Laravel-isms: a target that is gone most likely
        // means the fix landed upstream, and aborting an install over a bug that is
        // no longer there would be absurd.
        unlink($this->root.'/electron-plugin/src/preload/index.mts');
        file_put_contents($this->serverDir().'/utils.ts', "export async function notifyLaravel() {}\n");

        $applied = implode("\n", (new RuntimePatcher())->patch($this->root));

        self::assertStringContainsString('index.mts is absent', $applied);
        self::assertStringContainsString('assuming it is fixed upstream', $applied);
    }

    public function testEveryHunkStillMatchesTheRealUpstreamRuntime(): void
    {
        // The fixtures in ScaffoldsRuntime are quoted from upstream by hand, so they can
        // drift from it without anything failing — the patcher would keep passing against
        // a copy of code that no longer exists. This runs it against the actual checkout,
        // which is the only thing that can catch upstream moving a target. Skips when the
        // clone is absent, exactly as ContractCoverageTest does.
        $upstream = __DIR__.'/../../upstream/np-desktop/resources/electron';

        if (!is_dir($upstream.'/electron-plugin/src/server')) {
            self::markTestSkipped('upstream/np-desktop is not checked out.');
        }

        $copy = sys_get_temp_dir().'/np-upstream-'.bin2hex(random_bytes(5));
        $fs = new Filesystem();
        $fs->mirror($upstream.'/electron-plugin/src', $copy.'/electron-plugin/src');

        try {
            $applied = implode("\n", (new RuntimePatcher())->patch($copy));

            // The Laravel-isms are the strict hunks: a build whose runtime still spawns
            // `artisan` does not start at all, so a missed one must fail here rather than
            // on someone's machine. The bug fixes are deliberately non-strict — they report
            // "assuming it is fixed upstream" when their target is gone, which is what
            // should happen once the open PRs land.
            foreach ([
                'CLI entrypoint',
                'router script',
                'APP_ENV',
                'scheduler tick',
                'guard the storage/ copy',
                'skip Laravel\'s optimize',
                'skip Laravel\'s migrate',
            ] as $hunk) {
                self::assertStringContainsString($hunk, $applied, sprintf('The "%s" hunk no longer matches upstream.', $hunk));
            }
        } finally {
            $fs->remove($copy);
        }
    }

    public function testAPatchThatCannotBeWrittenIsAnError(): void
    {
        // The one outcome this class exists to prevent is a patch that is reported as
        // applied but is not on disk: the app then launches with Laravel's hardcoded
        // paths and shows a window that never does anything. An unwritable file used to
        // produce exactly that, because the write's return value was discarded.
        chmod($this->serverDir().'/php.ts', 0o444);

        try {
            $this->expectException(PatchFailed::class);
            $this->expectExceptionMessageMatches('/could not write it back/');

            (new RuntimePatcher())->patch($this->root);
        } finally {
            chmod($this->serverDir().'/php.ts', 0o644);
        }
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root.'/electron-plugin/src/'.$relative);
    }

    private function phpTs(): string
    {
        return (string) file_get_contents($this->serverDir().'/php.ts');
    }

    private function childProcessTs(): string
    {
        return (string) file_get_contents($this->serverDir().'/api/childProcess.ts');
    }

}
