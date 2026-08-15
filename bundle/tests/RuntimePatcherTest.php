<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Runtime\PatchFailed;
use Native\Symfony\Runtime\RuntimePatcher;
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

        // Seven Laravel-isms plus the six bug fixes.
        self::assertCount(13, $applied);

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
