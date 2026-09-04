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
        // Normalised in the relative branch too. It used to come back verbatim here, which
        // was not a decision but `Path::join()`'s behaviour before symfony/filesystem 7.4 —
        // and it changed underneath the expectation while the range still claimed both.
        yield 'drive letter, on linux' => ['Linux', 'D:\\builds', '{project}/D:/builds/android'];
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
     * The rule, as a table rather than as a reading of the implementation — and asserted for
     * both platforms on whatever host runs the suite, which is the reason the platform is an
     * argument at all.
     */
    #[DataProvider('everyPathShape')]
    public function testTheRuleAnswersForBothPlatforms(string $path, bool $onPosix, bool $onWindows): void
    {
        self::assertSame($onPosix, (new ProjectPath($this->project, 'Linux'))->isAbsolute($path));
        self::assertSame($onWindows, (new ProjectPath($this->project, 'Windows'))->isAbsolute($path));
    }

    /**
     * And the table pinned to Symfony's own answers, so it is not just a reading of the port.
     *
     * `Path::isAbsolute()` only became host-aware in symfony/filesystem 7.4 and 8.1, both
     * inside this bundle's declared range: before that it answered for Windows on every host
     * and called `D:x` absolute, which even Windows treats as drive-relative. A disagreement
     * on a Windows-shaped input is that known difference, and the reason this port exists; a
     * disagreement on a shape with nothing platform-specific in it means one of the two is
     * wrong, and that holds on every version.
     *
     * Nothing skips. `--fail-on-skipped` is how CI notices an environment that has stopped
     * covering something, and a version difference is not that.
     */
    public function testTheTableAgreesWithSymfonyForThisHost(): void
    {
        $onWindowsHost = 'Windows' === \PHP_OS_FAMILY;
        $disagreements = [];

        foreach (self::everyPathShape() as [$path, $onPosix, $onWindows]) {
            if (Path::isAbsolute($path) !== ($onWindowsHost ? $onWindows : $onPosix)) {
                $disagreements[] = $path;
            }
        }

        self::assertSame(
            [],
            array_values(array_filter(
                $disagreements,
                static fn (string $path): bool => !str_contains($path, ':') && !str_contains($path, '\\'),
            )),
            'The table disagrees with Path::isAbsolute() about a shape with nothing '
            .'platform-specific in it, so one of the two is wrong rather than merely older.',
        );

        // The strong form, on the version almost everyone will have resolved.
        if (!$onWindowsHost && !Path::isAbsolute('D:\\builds')) {
            self::assertSame(
                [],
                $disagreements,
                'This symfony/filesystem is host-aware, so the port has to agree with it everywhere.',
            );
        }
    }

    /**
     * @return iterable<string, array{string, bool, bool}> path, absolute on POSIX, on Windows
     */
    public static function everyPathShape(): iterable
    {
        foreach ([
            ['/mnt/builds', true, true],
            ['var/staging', false, false],
            ['', false, false],
            ['./x', false, false],
            ['../x', false, false],

            // Windows only.
            ['D:\\builds', false, true],
            ['D:/builds', false, true],
            ['D:', false, true],
            ['\\\\build\\share', false, true],
            ['\\single', false, true],

            // Near misses: drive-relative, and a two-letter prefix.
            ['D:x', false, false],
            ['DD:/x', false, false],

            // A scheme is absolute everywhere.
            ['file:///mnt/builds', true, true],
            ['phar:///app.phar/x', true, true],
        ] as [$path, $onPosix, $onWindows]) {
            yield ('' === $path ? '(empty)' : $path) => [$path, $onPosix, $onWindows];
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
