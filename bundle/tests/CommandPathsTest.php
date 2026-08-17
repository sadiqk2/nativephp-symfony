<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Command\BuildCommand;
use Native\Symfony\Command\ManifestCommand;
use Native\Symfony\Command\RunCommand;
use Native\Symfony\Manifest\Manifest;
use Native\Symfony\Manifest\ManifestSupportDetector;
use Native\Symfony\Manifest\ManifestWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * How `native:run`, `native:build` and `native:manifest` turn `--electron-path`
 * into a path.
 *
 * None of the three had a test of its own, which is how a `Path::` call shipped in
 * `RunCommand` with no import for it — every invocation of the command would have
 * died on `Native\Symfony\Command\Path` before printing anything, and 349 green
 * tests said nothing about it. `native:manifest` then turned out to still carry the
 * POSIX-only check the other two had just had removed. These run the commands far
 * enough to hit the resolution and read the path back out of the output.
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
        // native:manifest resolves --electron-path too, and had the same POSIX-only test
        // as the other two — found only by grepping for the pattern after fixing them,
        // which is the lesson: when a fix ships, sweep the tree for what it replaced.
        $tester = $this->execute($this->manifestCommand(), []);

        self::assertStringContainsString($this->project.'/nativephp/electron', $this->flatten($tester));
    }

    #[DataProvider('absolutePaths')]
    public function testManifestLeavesAnAbsoluteElectronPathAlone(string $path, string $expected, bool $windowsOnly): void
    {
        if ($windowsOnly && '\\' !== \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('Drive letters and UNC paths are only absolute on Windows.');
        }

        $output = $this->flatten($this->execute($this->manifestCommand(), ['--electron-path' => $path]));

        self::assertStringContainsString($expected, $output);
        self::assertStringNotContainsString($this->project.'/'.$path, $output);
    }

    /**
     * `str_starts_with($path, '/')` is not "is this absolute" anywhere but POSIX.
     * A Windows path failed it and was appended to the project directory, so
     * `--electron-path=C:\dev\electron` looked for C:\proj\C:\dev\electron and
     * reported a project that exists as missing.
     */
    #[DataProvider('absolutePaths')]
    public function testAnAbsolutePathIsLeftAlone(string $path, string $expected, bool $windowsOnly): void
    {
        if ($windowsOnly && '\\' !== \DIRECTORY_SEPARATOR) {
            // `Path::isAbsolute` gates the drive-letter and UNC forms on
            // DIRECTORY_SEPARATOR, which is right: on Linux "C:\dev" really is a
            // relative path, one weirdly-named directory deep. So these two rows
            // only mean anything on the platform the bug was about — they are kept
            // here to run there rather than deleted for being inconvenient.
            self::markTestSkipped('Drive letters and UNC paths are only absolute on Windows.');
        }

        foreach ([$this->execute(new RunCommand($this->project), ['--electron-path' => $path]),
            $this->execute(new BuildCommand($this->project, []), ['os' => 'linux', '--electron-path' => $path])] as $tester) {
            $output = $this->flatten($tester);

            self::assertStringContainsString($expected, $output);
            self::assertStringNotContainsString($this->project.'/'.$path, $output);
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function absolutePaths(): iterable
    {
        // Path normalises separators, so the expectation is the forward-slash form.
        yield 'posix' => ['/opt/electron', '/opt/electron', false];
        // Runs everywhere, and is the only row that does: the drive-letter and UNC
        // forms are only absolute on Windows, so on any other platform a stream
        // wrapper is the one input where Path::isAbsolute and the old leading-slash
        // test disagree — which is what keeps this suite able to fail here at all.
        yield 'stream wrapper' => ['file:///opt/electron', 'file:///opt/electron', false];
        yield 'windows drive' => ['C:\\dev\\electron', 'C:/dev/electron', true];
        yield 'windows unc' => ['\\\\server\\share\\electron', '//server/share/electron', true];
    }

    /**
     * `native:manifest` with the real collaborators — only the report is under test, and
     * it is reached through the write, so the command is run with --dry-run nowhere: the
     * write goes to the throwaway project directory.
     */
    private function manifestCommand(): ManifestCommand
    {
        $manifest = new Manifest();

        return new ManifestCommand(
            new ManifestWriter($this->project, $manifest),
            $manifest,
            new ManifestSupportDetector(),
            $this->project,
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
        // SymfonyStyle wraps error blocks at the terminal width, so a long path
        // arrives split across lines.
        return preg_replace('/\s+/', '', $tester->getDisplay()) ?? '';
    }
}
