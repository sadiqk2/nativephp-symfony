<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Command\InstallCommand;
use Native\Symfony\Desktop\Runtime\RuntimePatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `native:install`, and in particular the routes import.
 *
 * That import is the install step with the worst failure mode in the whole bundle: forget
 * it and the app builds, launches, opens a window, and shows nothing forever — `/booted`
 * 404s so `AppBootstrapper::boot()` never runs, and the 404 is logged by Electron rather
 * than anywhere a Symfony developer would look. It was a documented manual step, was
 * forgotten while building the demo, and cost real time to find. So it is now written by
 * the installer and pinned here.
 */
final class InstallCommandTest extends TestCase
{
    use ScaffoldsRuntime;

    private string $root;

    private string $project;

    private string $source;

    private function runtimeRoot(): string
    {
        return $this->source;
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/np-install-'.bin2hex(random_bytes(6));
        $this->project = $this->root.'/project';
        $this->source = $this->root.'/upstream';

        // A Flex-shaped application: config/routes/ exists, public/ exists.
        (new Filesystem())->mkdir([$this->project.'/config/routes', $this->project.'/public']);
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** @param array<string, mixed> $options */
    private function install(array $options = []): CommandTester
    {
        $tester = new CommandTester(new InstallCommand($this->project, new RuntimePatcher()));
        $tester->execute([
            '--source' => $this->source,
            '--skip-npm' => true,
            ...$options,
        ]);

        return $tester;
    }

    private function routesFile(): string
    {
        return $this->project.'/config/routes/native_desktop.yaml';
    }

    public function testItWritesTheRoutesImport(): void
    {
        $tester = $this->install();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->routesFile());

        $yaml = (string) file_get_contents($this->routesFile());
        self::assertStringContainsString("resource: '@NativeDesktopBundle/src/Resources/config/routes.php'", $yaml);
        self::assertStringContainsString('type: php', $yaml);
    }

    public function testTheWrittenImportIsShapedLikeValidYaml(): void
    {
        $this->install();

        $lines = explode("\n", (string) file_get_contents($this->routesFile()));
        $lines = array_values(array_filter($lines, static fn (string $l): bool => '' !== trim($l)));

        // Indentation, asserted explicitly. PHP's flexible heredoc strips the closing
        // marker's indentation from every line, so getting that wrong yields a file that
        // still contains all the right substrings and is not valid YAML — the routes would
        // then fail to load with a parse error rather than be absent, which is a different
        // bug with a different symptom. symfony/yaml is not in this vendor tree, so the
        // shape is checked directly rather than by parsing.
        $keys = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, '#')));

        self::assertSame('native_desktop:', $keys[0]);
        self::assertMatchesRegularExpression('/^ {4}resource: /', $keys[1]);
        self::assertMatchesRegularExpression('/^ {4}type: php$/', $keys[2]);

        // Every comment line must be a comment at column 0, not indented into the mapping.
        foreach ($lines as $line) {
            if (str_contains($line, '#')) {
                self::assertStringStartsWith('#', $line);
            }
        }

        // The resource has to resolve to a file that exists in this bundle, or the app
        // fails to boot with a routing error instead of booting without the routes.
        preg_match("/resource: '@NativeDesktopBundle\/(.*)'/", $keys[1], $m);
        self::assertNotEmpty($m, $keys[1]);
        self::assertFileExists(\dirname(__DIR__).'/'.$m[1]);
    }

    public function testItDoesNotClobberAnEditedImport(): void
    {
        // An app may have added a prefix or a condition. Reverting that silently would be
        // worse than leaving the file alone.
        file_put_contents($this->routesFile(), "native_desktop:\n    resource: mine\n");

        $tester = $this->install();

        self::assertStringContainsString('mine', (string) file_get_contents($this->routesFile()));
        self::assertStringContainsString('already present', $tester->getDisplay());
    }

    public function testForceRewritesIt(): void
    {
        file_put_contents($this->routesFile(), "native_desktop:\n    resource: mine\n");

        $this->install(['--force' => true]);

        self::assertStringContainsString('@NativeDesktopBundle', (string) file_get_contents($this->routesFile()));
    }

    public function testWithoutAConfigRoutesDirectoryItWarnsRatherThanGuessing(): void
    {
        // Not every application is Flex-shaped. Writing the file somewhere nothing loads
        // it would report success and still give the blank-window failure.
        (new Filesystem())->remove($this->project.'/config');

        $tester = $this->install();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->routesFile());

        // Whitespace-collapsed: SymfonyStyle wraps a warning block to the terminal width,
        // so a phrase can land across two lines.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('the app will boot and show nothing', $display);
        self::assertStringContainsString('@NativeDesktopBundle/src/Resources/config/routes.php', $display);
    }

    public function testItStillInstallsTheRouterAndPatchesTheRuntime(): void
    {
        $this->install();

        self::assertFileExists($this->project.'/public/nativephp-router.php');

        $php = (string) file_get_contents($this->project.'/nativephp/electron/electron-plugin/src/server/php.ts');
        self::assertStringContainsString("['bin/console', 'native:config']", $php);
    }

    public function testAMissingSourceIsRefusedWithTheCommandToRun(): void
    {
        $tester = new CommandTester(new InstallCommand($this->project, new RuntimePatcher()));
        $tester->execute(['--skip-npm' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('git clone', $tester->getDisplay());
    }
}
