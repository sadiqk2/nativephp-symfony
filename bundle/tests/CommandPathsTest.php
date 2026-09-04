<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Command\BuildCommand;
use Native\Symfony\Desktop\Command\ManifestCommand;
use Native\Symfony\Desktop\Command\RunCommand;
use Native\Symfony\Desktop\Manifest\Manifest;
use Native\Symfony\Desktop\Manifest\ManifestSupportDetector;
use Native\Symfony\Desktop\Manifest\ManifestWriter;
use Native\Symfony\Desktop\Support\Platform;
use Native\Symfony\Desktop\Support\ProjectPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * How `native:run`, `native:build` and `native:manifest` turn `--electron-path` into a path.
 *
 * None of the three had a test of its own, which is how a `Path::` call shipped in
 * `RunCommand` with no import for it — every invocation would have died before printing
 * anything, and 349 green tests said nothing. `native:manifest` then turned out to still
 * carry the POSIX-only check the other two had just had removed.
 *
 * The Windows rows used to be skipped off Windows, because `Path::isAbsolute()` decides from
 * `DIRECTORY_SEPARATOR`. That hid a second mistake: they expected `C:/dev/electron` from code
 * that returned the path verbatim, so they would have *failed* on the only platform that ran
 * them. The commands now resolve through {@see ProjectPath}, which takes the platform as an
 * argument, so every row runs on every host — and the expectations have been checked rather
 * than assumed.
 */
final class CommandPathsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/np-symfony-paths-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->project);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    // ── Each command resolves a relative option against the project ──

    public function testRunResolvesARelativeElectronPathAgainstTheProject(): void
    {
        $tester = $this->execute(new RunCommand($this->project), []);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($this->project.'/nativephp/electron', $this->flatten($tester));
    }

    public function testBuildResolvesARelativeElectronPathAgainstTheProject(): void
    {
        $tester = $this->execute(new BuildCommand($this->project, []), ['os' => 'linux']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($this->project.'/nativephp/electron', $this->flatten($tester));
    }

    public function testManifestResolvesARelativeElectronPathAgainstTheProject(): void
    {
        // Found only by grepping for the pattern after fixing the other two, which is the
        // lesson: when a fix ships, sweep the tree for what it replaced.
        $tester = $this->execute($this->manifestCommand(), []);

        self::assertStringContainsString($this->project.'/nativephp/electron', $this->flatten($tester));
    }

    // ── The same table, on every platform, through every command ──

    #[DataProvider('paths')]
    public function testEveryCommandResolvesAPathTheSameWay(string $osFamily, string $path, string $expected): void
    {
        $platform = new Platform($osFamily);
        $expected = str_replace('{project}', $this->project, $expected);

        foreach ([
            'native:run' => $this->execute(new RunCommand($this->project, $platform), ['--electron-path' => $path]),
            'native:build' => $this->execute(new BuildCommand($this->project, [], $platform), ['os' => 'linux', '--electron-path' => $path]),
            'native:manifest' => $this->execute($this->manifestCommand($platform), ['--electron-path' => $path]),
        ] as $command => $tester) {
            self::assertStringContainsString(
                $this->squeeze($expected),
                $this->flatten($tester),
                sprintf('%s should report %s for "%s" on %s', $command, $expected, $path, $osFamily),
            );
        }
    }

    /**
     * Every row is asserted on whatever host runs the suite: the platform is an argument, not
     * `DIRECTORY_SEPARATOR`.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function paths(): iterable
    {
        // Absolute on both platforms.
        yield 'posix, on linux' => ['Linux', '/opt/electron', '/opt/electron'];
        yield 'posix, on windows' => ['Windows', '/opt/electron', '/opt/electron'];

        // A stream wrapper is absolute everywhere — and it is the only input where this rule
        // and the old leading-slash test disagree on a non-Windows host, so it is what keeps
        // this suite able to fail here at all.
        yield 'stream wrapper, on linux' => ['Linux', 'file:///opt/electron', 'file:///opt/electron'];
        yield 'stream wrapper, on windows' => ['Windows', 'file:///opt/electron', 'file:///opt/electron'];

        // Absolute on Windows, and genuinely relative anywhere else: on Linux `C:\dev` is one
        // directory whose name contains a colon and backslashes, so resolving it against the
        // project is the correct answer rather than a compromise.
        yield 'drive letter, on windows' => ['Windows', 'C:\\dev\\electron', 'C:/dev/electron'];
        // Asserted as far as the project prefix, and no further, on purpose. What this row
        // is for is that the drive letter is treated as *relative* off Windows, and that is
        // the whole of `{project}/C:`. What comes after it is what `Path::join()` does with
        // a backslash inside a POSIX filename, which it rewrote before symfony/filesystem
        // 7.4 and leaves alone now — a difference this bundle cannot take back from it, and
        // pathological input either way. Pinning either answer would fail half the range.
        yield 'drive letter, on linux' => ['Linux', 'C:\\dev\\electron', '{project}/C:'];
        yield 'unc, on windows' => ['Windows', '\\\\server\\share\\electron', '//server/share/electron'];

        // A bare drive letter is absolute on Windows; `Path::isAbsolute()` special-cases it.
        yield 'bare drive letter, on windows' => ['Windows', 'C:', 'C:'];

        // Normalised because the platform is Windows, where a backslash *is* a separator —
        // not because it is a backslash. This row used to expect the path back verbatim,
        // which was never a decision: it was `Path::join()`'s behaviour before
        // symfony/filesystem 7.4, and it changed underneath the expectation. Doing it in
        // ProjectPath, and only for Windows, is what makes this answer the same on every
        // version in the declared range without corrupting a POSIX name.
        yield 'relative, on windows' => ['Windows', 'nativephp\\electron', '{project}/nativephp/electron'];
        yield 'relative, on linux' => ['Linux', 'nativephp/electron', '{project}/nativephp/electron'];
    }

    // ── The rule itself, pinned to Symfony's ──

    /**
     * The rule itself, as a table rather than as a reading of the implementation.
     *
     * Every shape is asserted for both platforms on whatever host runs the suite, which is
     * the whole reason the platform is an argument to {@see ProjectPath} — the Windows rows
     * used to be skipped off Windows, and a skipped assertion is an unverified guess.
     */
    #[DataProvider('everyPathShape')]
    public function testTheRuleAnswersForBothPlatforms(string $path, bool $onPosix, bool $onWindows): void
    {
        self::assertSame($onPosix, (new ProjectPath($this->project, new Platform('Linux')))->isAbsolute($path));
        self::assertSame($onWindows, (new ProjectPath($this->project, new Platform('Windows')))->isAbsolute($path));
    }

    /**
     * And the table pinned to Symfony's own answers, so it is not just my reading of it.
     *
     * `ProjectPath::isAbsolute()` is a port of `Path::isAbsolute()` with the platform passed
     * in rather than read from `DIRECTORY_SEPARATOR`, so on a `Path` that is itself
     * host-aware the two must agree exactly for the host platform.
     *
     * `Path` only became host-aware in symfony/filesystem 7.4 and 8.1, which is inside this
     * bundle's declared support range: before that it answered for Windows on every host,
     * and called `C:x` absolute where even Windows treats it as drive-relative. So a
     * disagreement on a Windows-shaped input is that known difference and is the reason the
     * port exists; a disagreement on a shape with nothing platform-specific about it means
     * one of the two is simply wrong, and that is asserted on every version.
     *
     * One test rather than one per row, because the useful statement is about the *set* of
     * disagreements. Nothing here skips: `--fail-on-skipped` is how CI notices an
     * environment that has quietly stopped covering something, and a version difference is
     * not that.
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
        if (!$onWindowsHost && !Path::isAbsolute('C:\\dev')) {
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
            // Absolute everywhere, or nowhere.
            ['/opt/electron', true, true],
            ['opt/electron', false, false],
            ['nativephp/electron', false, false],
            ['', false, false],
            ['./relative', false, false],
            ['../up', false, false],

            // Windows only: on POSIX these are one directory whose name happens to
            // contain a colon and some backslashes.
            ['C:\\dev\\electron', false, true],
            ['C:/dev/electron', false, true],
            ['C:', false, true],
            ['\\\\server\\share', false, true],
            ['\\single', false, true],

            // Near misses that are relative on both: a two-letter prefix, a digit, and a
            // drive letter followed by something other than a separator.
            ['C:x', false, false],
            ['CC:/x', false, false],
            ['1:/x', false, false],

            // A scheme is absolute everywhere.
            ['file:///opt/electron', true, true],
            ['phar:///app.phar/x', true, true],
            ['https://example.com/x', true, true],
            ['a://b', true, true],
        ] as [$path, $onPosix, $onWindows]) {
            yield ('' === $path ? '(empty)' : $path) => [$path, $onPosix, $onWindows];
        }
    }

    public function testABackslashInAPosixNameIsPartOfTheNameRatherThanASeparator(): void
    {
        // `absolute()` rewrote backslashes on every platform, which is only ever free on
        // Windows. On POSIX the character is legal in a filename, so the rewrite named a
        // *different* directory: `--electron-path=/opt/my\dir` reported a directory that
        // exists as missing. It also contradicted `isAbsolute()` directly below it, whose
        // whole Linux branch rests on `C:\dev` being one oddly-named directory there.
        $posix = new ProjectPath($this->project, new Platform('Linux'));

        self::assertSame('/opt/my\\dir', $posix->absolute('/opt/my\\dir'));

        // On Windows the same rewrite costs nothing, because there it *is* a separator and
        // both spellings name the same directory.
        $windows = new ProjectPath($this->project, new Platform('Windows'));

        self::assertSame('C:/my/dir', $windows->absolute('C:\\my\\dir'));
        self::assertSame($this->project.'/my/dir', $windows->absolute('my\\dir'));
    }

    public function testAnEmptyOptionIsTheProjectDirectory(): void
    {
        // Not a crash and not the filesystem root: `Path::join()` treats it as nothing.
        $paths = new ProjectPath($this->project, new Platform('Linux'));

        self::assertSame($this->project, $paths->absolute(''));
    }

    private function manifestCommand(?Platform $platform = null): ManifestCommand
    {
        $manifest = new Manifest();

        return new ManifestCommand(
            new ManifestWriter($this->project, $manifest),
            $manifest,
            new ManifestSupportDetector(),
            $this->project,
            $platform,
        );
    }

    /** @param array<string, string> $input */
    private function execute(Command $command, array $input): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function flatten(CommandTester $tester): string
    {
        // SymfonyStyle wraps error blocks at the terminal width, so a long path arrives split
        // across lines.
        return $this->squeeze($tester->getDisplay());
    }

    private function squeeze(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? '';
    }
}
