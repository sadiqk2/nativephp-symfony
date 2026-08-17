<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Command\BuildCommand;
use Native\Symfony\Command\RunCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * How `native:run` and `native:build` turn `--electron-path` into a path.
 *
 * Neither command had a test of its own, which is how a `Path::` call shipped in
 * `RunCommand` with no import for it — every invocation of the command would have
 * died on `Native\Symfony\Command\Path` before printing anything, and 349 green
 * tests said nothing about it. These run the commands far enough to hit the
 * resolution and read the path back out of the error message.
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
        yield 'windows drive' => ['C:\\dev\\electron', 'C:/dev/electron', true];
        yield 'windows unc' => ['\\\\server\\share\\electron', '//server/share/electron', true];
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
