<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Build\Toolchain;
use Native\Symfony\Mobile\Command\MobileBuildCommand;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Where `native:mobile:build --stage-dir` actually stages.
 *
 * The command had no test of its own, and carried the same POSIX-only "is this absolute"
 * check that `native:run` and `native:build` had just had removed — found by sweeping the
 * tree for the pattern the fix replaced rather than by anything failing. It matters more
 * here than in the desktop commands: staging creates whatever directory it is handed and
 * fills it with a copy of the whole application, so treating `D:\builds` as relative puts
 * several hundred megabytes inside the project instead, silently.
 *
 * `--dry-run` writes nothing and prints the resolved stage path, which is the whole
 * observation this needs.
 */
final class MobileCommandPathsTest extends TestCase
{
    private string $project = '/srv/app';

    public function testARelativeStageDirIsResolvedAgainstTheProject(): void
    {
        $output = $this->build(['--stage-dir' => 'var/staging']);

        self::assertStringContainsString('/srv/app/var/staging/android', $output);
    }

    public function testTheDefaultStageDirIsRelativeToo(): void
    {
        self::assertStringContainsString('/srv/app/var/nativephp/mobile/android', $this->build([]));
    }

    #[DataProvider('absoluteStageDirs')]
    public function testAnAbsoluteStageDirIsLeftAlone(string $dir, string $expected, bool $windowsOnly): void
    {
        if ($windowsOnly && '\\' !== \DIRECTORY_SEPARATOR) {
            // Path::isAbsolute gates the drive-letter and UNC forms on DIRECTORY_SEPARATOR,
            // correctly — on Linux "D:\builds" is a directory with an odd name. The rows are
            // kept so they run on the platform the bug was about.
            self::markTestSkipped('Drive letters and UNC paths are only absolute on Windows.');
        }

        $output = $this->build(['--stage-dir' => $dir]);

        self::assertStringContainsString($expected, $output);
        self::assertStringNotContainsString($this->project.'/'.$dir, $output);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function absoluteStageDirs(): iterable
    {
        yield 'posix' => ['/mnt/builds', '/mnt/builds/android', false];
        // The one row that discriminates on a non-Windows host, and therefore the one
        // keeping this suite able to fail here at all.
        yield 'stream wrapper' => ['file:///mnt/builds', 'file:///mnt/builds/android', false];
        yield 'windows drive' => ['D:\\builds', 'D:/builds/android', true];
        yield 'windows unc' => ['\\\\build\\share', '//build/share/android', true];
    }

    /** @param array<string, string> $options */
    private function build(array $options): string
    {
        $command = new MobileBuildCommand(
            projectDir: $this->project,
            version: '1.0.0',
            manifest: new NativeRouteManifest(new NativeRouteRegistry(), '1.0.0'),
            toolchain: new Toolchain([]),
        );

        $tester = new CommandTester($command);
        $tester->execute(['platform' => 'android', '--dry-run' => true] + $options);

        // SymfonyStyle wraps at the terminal width, so a long path arrives split.
        return preg_replace('/\s+/', '', $tester->getDisplay()) ?? '';
    }
}
