<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Bridge\FakeBridge;
use Native\Symfony\Mobile\Command\DoctorCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `native:mobile:doctor` — the only diagnostic mobile has.
 *
 * There is no headless way to run a mobile app here, so this command is what a
 * developer reads in logcat when a screen does not appear. It had no test, and until
 * now it also did not check the thing most likely to be wrong: whether the hosts'
 * compiled-in dispatch was retargeted. An app whose bootstrap paths are patched and
 * whose host evals are not looks completely healthy and serves nothing.
 */
final class MobileDoctorCommandTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/np-mobile-doctor-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testItReportsAnUninstalledProject(): void
    {
        $display = $this->doctor();

        self::assertStringContainsString('✗ android', $display);
        self::assertStringContainsString('not installed', $display);
    }

    public function testItNamesTheFixWhenTheHostStillCallsLaravel(): void
    {
        $this->writeHostSource('android', '"    $__response = \\\\Native\\\\Mobile\\\\Runtime::dispatch(\n"');

        $display = $this->doctor();

        self::assertStringContainsString('host dispatch still calls Laravel', $display);
        self::assertStringContainsString('native:mobile:install', $display);
    }

    public function testItConfirmsARetargetedHost(): void
    {
        $this->writeHostSource('android', '"    $__response = \\\\Native\\\\Symfony\\\\Mobile\\\\Runtime\\\\MobileRuntime::dispatch(\n"');

        self::assertStringContainsString('host dispatch retargeted', $this->doctor());
    }

    public function testAProjectWithoutHostSourcesSaysSoRatherThanPassing(): void
    {
        (new Filesystem())->mkdir($this->project.'/nativephp/android');

        self::assertStringContainsString('no host sources found', $this->doctor());
    }

    private function writeHostSource(string $platform, string $line): void
    {
        (new Filesystem())->dumpFile(
            $this->project.'/nativephp/'.$platform.'/app/src/main/cpp/php_bridge.c',
            "// a fragment of the host's dispatch preamble\n".$line."\n",
        );
    }

    private function doctor(): string
    {
        $tester = new CommandTester(new DoctorCommand($this->project, new FakeBridge()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        return $tester->getDisplay();
    }
}
