<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Build\Toolchain;
use Native\Symfony\Mobile\Command\MobileBuildCommand;
use Native\Symfony\Mobile\Support\ProjectPath;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Path;

/**
 * Where `native:mobile:build --stage-dir` actually stages.
 *
 * The command had no test of its own and carried the same POSIX-only "is this absolute" check
 * that `native:run` and `native:build` had just had removed — found by sweeping the tree for
 * the pattern the fix replaced, not by anything failing. It matters more here than in the
 * desktop commands: staging creates whatever directory it is handed and fills it with a copy
 * of the whole application, so treating `D:\builds` as relative puts several hundred megabytes
 * inside the project instead, silently.
 *
 * The Windows rows were skipped off Windows at first, since `Path::isAbsolute()` decides from
 * `DIRECTORY_SEPARATOR`. Resolution now goes through {@see ProjectPath}, which takes the
 * platform as an argument, so the whole table runs on every host.
 *
 * `--dry-run` writes nothing and prints the resolved stage path, which is the whole
 * observation this needs.
 */
final class MobileCommandPathsTest extends TestCase
{
    private string $project = '/srv/app';

    public function testTheDefaultStageDirIsRelativeToTheProject(): void
    {
        self::assertStringContainsString('/srv/app/var/nativephp/mobile/android', $this->build('Linux', []));
    }

    #[DataProvider('stageDirs')]
    public function testAStageDirResolvesTheSameWayOnEveryHost(string $osFamily, string $dir, string $expected): void
    {
        $output = $this->build($osFamily, ['--stage-dir' => $dir]);

        self::assertStringContainsString(
            preg_replace('/\s+/', '', str_replace('{project}', $this->project, $expected)) ?? '',
            $output,
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function stageDirs(): iterable
    {
        yield 'relative, on linux' => ['Linux', 'var/staging', '{project}/var/staging/android'];
        yield 'relative, on windows' => ['Windows', 'var/staging', '{project}/var/staging/android'];

        yield 'posix, on linux' => ['Linux', '/mnt/builds', '/mnt/builds/android'];
        yield 'posix, on windows' => ['Windows', '/mnt/builds', '/mnt/builds/android'];

        // Absolute on Windows only — on Linux `D:\builds` is one oddly-named directory, and
        // resolving it against the project is the right answer there.
        yield 'drive letter, on windows' => ['Windows', 'D:\\builds', 'D:/builds/android'];
        yield 'drive letter, on linux' => ['Linux', 'D:\\builds', '{project}/D:\\builds/android'];
        yield 'unc, on windows' => ['Windows', '\\\\build\\share', '//build/share/android'];

        // The one row that discriminates from a leading-slash test on any platform.
        yield 'stream wrapper, on linux' => ['Linux', 'file:///mnt/builds', 'file:///mnt/builds/android'];
    }

    public function testAUncPrefixSurvivesTheJoinOnAnyHost(): void
    {
        // Path::join() recognises roots through DIRECTORY_SEPARATOR, so on Linux it collapsed
        // //build/share to /build/share — a resolved Windows path quietly turned into a
        // plausible Linux one, which is precisely the class of answer a skipped test hides.
        $paths = new ProjectPath($this->project, 'Windows');

        self::assertSame('//build/share/android', $paths->join('\\\\build\\share', 'android'));
    }

    /**
     * The rule is a port of `Path::isAbsolute()` with the platform injected, so it has to
     * agree with the original for the host platform — the only one `Path` can answer for.
     */
    #[DataProvider('everyPathShape')]
    public function testTheRuleAgreesWithSymfonyForThisHost(string $path): void
    {
        $paths = new ProjectPath($this->project, \PHP_OS_FAMILY);

        self::assertSame(Path::isAbsolute($path), $paths->isAbsolute($path), sprintf('"%s" on %s', $path, \PHP_OS_FAMILY));
    }

    /** @return iterable<string, array{string}> */
    public static function everyPathShape(): iterable
    {
        foreach ([
            '/mnt/builds', 'var/staging', '', 'D:\\builds', 'D:/builds', 'D:', 'D:x', 'DD:/x',
            '\\\\build\\share', '\\single', 'file:///mnt/builds', 'phar:///app.phar/x', './x', '../x',
        ] as $path) {
            yield ('' === $path ? '(empty)' : $path) => [$path];
        }
    }

    /** @param array<string, string> $options */
    private function build(string $osFamily, array $options): string
    {
        $command = new MobileBuildCommand(
            projectDir: $this->project,
            version: '1.0.0',
            manifest: new NativeRouteManifest(new NativeRouteRegistry(), '1.0.0'),
            toolchain: new Toolchain([], $osFamily),
            osFamily: $osFamily,
        );

        $tester = new CommandTester($command);
        $tester->execute(['platform' => 'android', '--dry-run' => true] + $options);

        // SymfonyStyle wraps at the terminal width, so a long path arrives split.
        return preg_replace('/\s+/', '', $tester->getDisplay()) ?? '';
    }
}
