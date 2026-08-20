<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Command\InstallCommand;
use Native\Symfony\Mobile\Runtime\MobileRuntimePatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `native:mobile:install` — copy both native projects into the app and retarget them.
 *
 * The one command in the mobile bundle that writes into somebody's project, and it had
 * no test: not the refusal to overwrite, not the platform filter, not whether the copy
 * it made was actually patched. That last one is the point of the command. A copy that
 * lands unpatched is an app that builds, installs, launches and then serves nothing,
 * with no error anywhere that names the cause.
 *
 * The source tree here is small but real — the four files each host is patched through,
 * with the strings quoted as upstream writes them. `HostEvalPatchTest` checks those
 * strings against the hosts' own sources; this checks that the command reaches them.
 */
final class MobileInstallCommandTest extends TestCase
{
    private string $project;
    private string $source;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir().'/np-mobile-install-'.bin2hex(random_bytes(4));

        $this->project = $root.'/app';
        $this->source = $root.'/resources';

        $fs = new Filesystem();

        $fs->dumpFile($this->source.'/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt', <<<'KT'
            object PHPBridge {
                private val nativePhpPath: String
                    get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/native.php"
                private val persistentPath: String
                    get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                private val artisanPath: String
                    get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/artisan.php"
            }
            KT);

        $fs->dumpFile($this->source.'/androidstudio/app/src/main/cpp/php_bridge.c', <<<'C'
            static void dispatch(void) {
                char eval_code[8192];
                snprintf(eval_code, sizeof(eval_code),
                    "try {\n"
                    "    $__response = \\Native\\Mobile\\Runtime::dispatch(\n"
                    "        \\Illuminate\\Http\\Request::capture()\n"
                    "    );\n"
                    "    echo $__response->getStatusCode();\n"
                    "} catch (\\Throwable $e) {\n"
                    "    echo 'error';\n"
                    "}\n");
                zend_eval_string(eval_code, NULL, "persistent_dispatch");
                zend_eval_string(
                    "(int) (class_exists('Native\\\\Mobile\\\\Runtime', false)"
                    " && \\Native\\Mobile\\Runtime::isBooted())",
                    &verify_result, "persistent_boot_verify");
                zend_eval_string("\\Native\\Mobile\\Runtime::shutdown();", NULL, "persistent_shutdown");
            }
            C);

        $fs->dumpFile($this->source.'/xcode/NativePHP/NativePHPApp.swift', <<<'SWIFT'
            let bootstrap = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"
            let persistent = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"
            SWIFT);

        $fs->dumpFile($this->source.'/xcode/Include/Bridge/PHP.c', <<<'C'
            static char eval_code[8192];
            snprintf(eval_code, sizeof(eval_code),
                "$__response = \\Native\\Mobile\\Runtime::dispatch(\n"
                "    \\Illuminate\\Http\\Request::capture()\n"
                ");\n");
            zend_eval_string("echo \\Native\\Mobile\\Runtime::isBooted() ? '1' : '0';", NULL, "boot_check");
            C);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(\dirname($this->project));
    }

    public function testItCopiesBothProjectsIntoTheApplication(): void
    {
        $tester = $this->execute(['--source' => $this->source]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->project.'/nativephp/android/app/src/main/cpp/php_bridge.c');
        self::assertFileExists($this->project.'/nativephp/ios/Include/Bridge/PHP.c');
    }

    public function testTheCopyIsRetargetedAtOurShims(): void
    {
        $this->execute(['--source' => $this->source]);

        $kotlin = (string) file_get_contents($this->project.'/nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt');

        self::assertStringNotContainsString('vendor/nativephp/mobile/bootstrap', $kotlin);
        self::assertStringContainsString(MobileRuntimePatcher::SHIM_DIR.'/native.php', $kotlin);
        self::assertStringContainsString(MobileRuntimePatcher::SHIM_DIR.'/persistent.php', $kotlin);
        // A Symfony app has no artisan, so the console entry point maps onto console.php.
        self::assertStringContainsString(MobileRuntimePatcher::SHIM_DIR.'/console.php', $kotlin);
        self::assertStringNotContainsString('artisan.php', $kotlin);
    }

    public function testTheHostsCompiledInDispatchIsRetargetedToo(): void
    {
        // The half that is invisible: patched paths with an unpatched eval gives an app
        // that looks correctly installed and serves nothing.
        $this->execute(['--source' => $this->source]);

        foreach ([
            $this->project.'/nativephp/android/app/src/main/cpp/php_bridge.c',
            $this->project.'/nativephp/ios/Include/Bridge/PHP.c',
        ] as $path) {
            $source = (string) file_get_contents($path);

            self::assertStringNotContainsString('Illuminate', $source, $path);
            self::assertStringNotContainsString('\\\\Native\\\\Mobile\\\\Runtime', $source, $path);
            self::assertStringContainsString('\\\\Native\\\\Symfony\\\\Mobile\\\\Runtime\\\\MobileRuntime', $source, $path);
        }
    }

    public function testTheReportNamesWhatItChanged(): void
    {
        $display = $this->flatten($this->execute(['--source' => $this->source]));

        self::assertStringContainsString('android php_bridge.c: host eval', $display);
        self::assertStringContainsString('Android PHPBridge.kt: native.php', $display);
        // The licence warning is not decoration: these sources are somebody's product.
        self::assertStringContainsString('LICENSE.md', $display);
    }

    public function testOnePlatformCanBeInstalledOnItsOwn(): void
    {
        $tester = $this->execute(['--source' => $this->source, '--platform' => 'ios']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryExists($this->project.'/nativephp/ios');
        self::assertDirectoryDoesNotExist($this->project.'/nativephp/android');
    }

    public function testAnExistingProjectIsNotOverwrittenWithoutForce(): void
    {
        $this->execute(['--source' => $this->source]);

        (new Filesystem())->dumpFile($this->project.'/nativephp/android/mine.txt', 'local change');

        $tester = $this->execute(['--source' => $this->source]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--force', $this->flatten($tester));
        self::assertFileExists($this->project.'/nativephp/android/mine.txt');
    }

    public function testForceReplacesTheProject(): void
    {
        $this->execute(['--source' => $this->source]);

        (new Filesystem())->dumpFile($this->project.'/nativephp/android/stale.txt', 'from an older install');

        $tester = $this->execute(['--source' => $this->source, '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        // mirror(delete: true) — a file the new sources do not have must not survive, or
        // an app keeps compiling something upstream removed.
        self::assertFileDoesNotExist($this->project.'/nativephp/android/stale.txt');
    }

    public function testInstallingTwiceIsSafe(): void
    {
        $this->execute(['--source' => $this->source]);

        $tester = $this->execute(['--source' => $this->source, '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $kotlin = (string) file_get_contents($this->project.'/nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt');

        self::assertSame(1, substr_count($kotlin, MobileRuntimePatcher::SHIM_DIR.'/native.php'));
    }

    public function testWithoutASourceItSaysWhereToGetOne(): void
    {
        $tester = $this->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('mobile-air', $this->flatten($tester));
    }

    public function testASourceWithNeitherProjectInstallsNothing(): void
    {
        $empty = \dirname($this->project).'/empty';
        (new Filesystem())->mkdir($empty);

        $tester = $this->execute(['--source' => $empty]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was installed', $this->flatten($tester));
    }

    /** @param array<string, mixed> $input */
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester(new InstallCommand($this->project, new MobileRuntimePatcher()));
        $tester->execute($input);

        return $tester;
    }

    private function flatten(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
