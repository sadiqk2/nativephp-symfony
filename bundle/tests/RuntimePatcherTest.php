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


    private function phpTs(): string
    {
        return (string) file_get_contents($this->serverDir().'/php.ts');
    }

    private function childProcessTs(): string
    {
        return (string) file_get_contents($this->serverDir().'/api/childProcess.ts');
    }

}
