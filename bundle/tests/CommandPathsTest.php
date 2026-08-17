<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Command\BuildCommand;
use Native\Symfony\Command\ManifestCommand;
use Native\Symfony\Command\RunCommand;
use Native\Symfony\Manifest\Manifest;
use Native\Symfony\Manifest\ManifestSupportDetector;
use Native\Symfony\Manifest\ManifestWriter;
use Native\Symfony\Support\Platform;
use Native\Symfony\Support\ProjectPath;
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
        yield 'drive letter, on linux' => ['Linux', 'C:\\dev\\electron', '{project}/C:\\dev\\electron'];
        yield 'unc, on windows' => ['Windows', '\\\\server\\share\\electron', '//server/share/electron'];

        // A bare drive letter is absolute on Windows; `Path::isAbsolute()` special-cases it.
        yield 'bare drive letter, on windows' => ['Windows', 'C:', 'C:'];

        yield 'relative, on windows' => ['Windows', 'nativephp\\electron', '{project}/nativephp\\electron'];
        yield 'relative, on linux' => ['Linux', 'nativephp/electron', '{project}/nativephp/electron'];
    }

    // ── The rule itself, pinned to Symfony's ──

    /**
     * `ProjectPath::isAbsolute()` is a port of `Path::isAbsolute()` with the platform passed
     * in, so the port has to agree with the original — for the *host* platform, which is the
     * only one `Path` can answer for. On Linux this pins the POSIX and stream-wrapper
     * branches; on a Windows runner it pins the drive-letter and UNC ones. Between the two the
     * whole rule is covered by something other than my own reading of it.
     */
    #[DataProvider('everyPathShape')]
    public function testTheRuleAgreesWithSymfonyForThisHost(string $path): void
    {
        $mine = new ProjectPath($this->project, new Platform(\PHP_OS_FAMILY));

        self::assertSame(
            Path::isAbsolute($path),
            $mine->isAbsolute($path),
            sprintf('Disagreed with Path::isAbsolute() about "%s" on %s', $path, \PHP_OS_FAMILY),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function everyPathShape(): iterable
    {
        foreach ([
            '/opt/electron', 'opt/electron', 'nativephp/electron', '',
            'C:\\dev\\electron', 'C:/dev/electron', 'C:', 'C:x', 'CC:/x', '1:/x',
            '\\\\server\\share', '\\single', 'file:///opt/electron', 'phar:///app.phar/x',
            'https://example.com/x', './relative', '../up', 'a://b',
        ] as $path) {
            yield ('' === $path ? '(empty)' : $path) => [$path];
        }
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
