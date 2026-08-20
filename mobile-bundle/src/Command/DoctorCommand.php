<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Command;

use Native\Symfony\Mobile\Bridge\BridgeInterface;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use Native\Symfony\Mobile\Runtime\MobileRuntime;
use Native\Symfony\Mobile\Runtime\MobileRuntimePatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Report what the mobile integration can see.
 *
 * Exists because mobile has no equivalent of the desktop dev loop: there is no
 * headless way to run an app, so the fastest available answer to "is this wired up
 * correctly" is to ask from inside it. Run this through the console shim on a
 * device and read it in logcat or the Xcode console.
 */
#[AsCommand(name: 'native:mobile:doctor', description: 'Report the mobile runtime environment')]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly BridgeInterface $bridge,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->definitionList(
            ['project dir' => $this->projectDir],
            ['php' => \PHP_VERSION.' ('.\PHP_OS_FAMILY.', '.\PHP_SAPI.')'],
            // Asking isAvailable() alone said "running inside a NativePHP app" to anyone
            // with fake_bridge on, because the fake answers true by construction — a
            // diagnostic confidently reporting the one thing it exists to tell you apart.
            ['bridge' => match (true) {
                $this->bridge instanceof FakeBridge => 'fake — native_mobile.fake_bridge is on, so calls are recorded, not sent',
                $this->bridge->isAvailable() => 'available — running inside a NativePHP app',
                default => 'unavailable — nativephp_call() is absent, so this is not a packaged app',
            }],
            ['persistent runtime' => MobileRuntime::isBooted()
                ? 'booted, '.MobileRuntime::instance()->dispatchCount().' dispatches'
                : 'not booted (one-shot mode, or a console invocation)'],
            ['opcache' => \function_exists('opcache_get_status') ? 'available' : 'NOT AVAILABLE'],
        );

        $io->section('Native projects');

        $patcher = new MobileRuntimePatcher();

        foreach (['android' => 'nativephp/android', 'ios' => 'nativephp/ios'] as $name => $path) {
            $full = $this->projectDir.'/'.$path;
            $io->text(sprintf(' %s %s — %s', is_dir($full) ? '✓' : '✗', $name, is_dir($full) ? $full : 'not installed'));

            if (!is_dir($full)) {
                continue;
            }

            // The one that looks like nothing when it is wrong. In persistent mode the
            // hosts do not execute a bootstrap file per request: they evaluate PHP
            // compiled into themselves, and unpatched it calls Laravel's runtime, so the
            // boot check fails and the interpreter is torn down before any screen loads.
            $io->text(match ($patcher->hostEvaluationsRetargeted($full, $name)) {
                true => '   ✓ host dispatch retargeted onto MobileRuntime',
                false => '   ✗ host dispatch still calls Laravel — re-run native:mobile:install',
                null => '   ? no host sources found to check',
            });
        }

        $io->section('Bootstrap shims');

        $shimDir = $this->projectDir.'/'.MobileRuntimePatcher::SHIM_DIR;

        foreach (['native.php', 'persistent.php', 'dispatch.php', 'console.php'] as $shim) {
            $io->text(sprintf(' %s %s', is_file($shimDir.'/'.$shim) ? '✓' : '✗', $shim));
        }

        if ($this->bridge instanceof FakeBridge) {
            // Worth saying out loud on a device: a build that ships with this on reaches
            // no native layer at all, and every call succeeds while doing nothing.
            $io->warning([
                'The fake bridge is installed, so no call reaches the native layer.',
                'That is what you want in a test or a browser, and never in a device build —',
                'set native_mobile.fake_bridge only in config/packages/test/.',
            ]);
        } elseif (!$this->bridge->isAvailable()) {
            $io->note([
                'Outside a packaged app the bridge is always unavailable, which is expected.',
                'Set native_mobile.fake_bridge: true to exercise the API in tests or a browser.',
            ]);
        }

        return Command::SUCCESS;
    }
}
