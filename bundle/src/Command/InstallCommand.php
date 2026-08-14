<?php

declare(strict_types=1);

namespace Native\Symfony\Command;

use Native\Symfony\Runtime\RuntimePatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Installs a patched copy of the Electron runtime into nativephp/electron/.
 *
 * The bundle does not vendor the runtime itself. `nativephp/desktop` ships it
 * under resources/electron, but requiring that package would drag
 * illuminate/contracts, laravel/prompts and spatie/laravel-package-tools into a
 * Symfony application — so the source is pointed at explicitly instead.
 */
#[AsCommand(name: 'native:install', description: 'Install and patch the Electron runtime for this app')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly RuntimePatcher $patcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED,
                'Path to NativePHP desktop\'s resources/electron directory')
            ->addOption('skip-npm', null, InputOption::VALUE_NONE,
                'Copy and patch only; do not run npm install or rebuild the plugin')
            ->addOption('force', 'f', InputOption::VALUE_NONE,
                'Overwrite an existing nativephp/electron directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fs = new Filesystem();

        $target = $this->projectDir.'/nativephp/electron';
        $source = $input->getOption('source');

        if (!\is_string($source) || '' === $source) {
            $io->error([
                'A --source is required: the path to NativePHP desktop\'s resources/electron directory.',
                'For example:',
                '  git clone --depth 1 https://github.com/NativePHP/desktop /tmp/np-desktop',
                '  bin/console native:install --source=/tmp/np-desktop/resources/electron',
            ]);

            return Command::INVALID;
        }

        if (!is_dir($source.'/electron-plugin/src/server')) {
            $io->error(sprintf('"%s" does not look like a NativePHP Electron project.', $source));

            return Command::INVALID;
        }

        if (is_dir($target) && !$input->getOption('force')) {
            $io->error(sprintf('%s already exists. Pass --force to overwrite it.', $target));

            return Command::FAILURE;
        }

        $io->section('Copying the Electron runtime');
        $fs->mkdir(\dirname($target));
        // node_modules and any prior build are the installer's to create.
        $fs->mirror($source, $target, options: ['override' => true, 'delete' => true]);
        $io->text(sprintf('Copied to %s', $target));

        $io->section('Patching the runtime for Symfony');
        foreach ($this->patcher->patch($target) as $line) {
            $io->text(' • '.$line);
        }

        $io->section('Installing the PHP server router');
        $router = $this->projectDir.'/public/nativephp-router.php';
        if (!is_file($router) || $input->getOption('force')) {
            $fs->copy(__DIR__.'/../Resources/runtime/nativephp-router.php', $router, true);
            $io->text(sprintf('Wrote %s', $router));
        } else {
            $io->text('Router already present; leaving it alone.');
        }

        $io->section('Registering the runtime routes');
        $this->installRoutes($fs, $io, (bool) $input->getOption('force'));

        if ($input->getOption('skip-npm')) {
            $io->success('Runtime installed and patched. Skipped npm.');

            return Command::SUCCESS;
        }

        $io->section('npm install (this downloads Electron; expect a few minutes)');
        if (Command::SUCCESS !== $this->runNpm($target, ['install', '--no-audit', '--no-fund'], $io)) {
            return Command::FAILURE;
        }

        $io->section('Rebuilding the plugin from the patched sources');
        // Without this the patched .ts files are ignored: the runtime loads
        // electron-plugin/dist/, which ships pre-compiled.
        if (Command::SUCCESS !== $this->runNpm($target, ['run', 'plugin:build'], $io)) {
            return Command::FAILURE;
        }

        $io->success('Runtime installed. Start it with: bin/console native:run');

        return Command::SUCCESS;
    }

    /**
     * The import for the runtime's two endpoints.
     *
     * A Symfony bundle cannot register routes for itself, so this file is what makes
     * `/_native/api/booted` and `/_native/api/events` exist. Omitting it is the worst
     * failure the install has: the app boots, the window opens, and nothing ever appears —
     * `/booted` 404s, so `AppBootstrapper::boot()` is never called, and the 404 is logged
     * by Electron rather than by the app. It cost real time to diagnose once, which is why
     * the installer now writes it rather than documenting it.
     *
     * Only ever created, never rewritten without `--force`: an existing file may have been
     * edited (a route prefix, a condition), and silently reverting that would be worse
     * than leaving it.
     */
    private function installRoutes(Filesystem $fs, SymfonyStyle $io, bool $force): void
    {
        $path = $this->projectDir.'/config/routes/native_desktop.yaml';

        if (is_file($path) && !$force) {
            $io->text('Routes import already present; leaving it alone.');

            return;
        }

        // No config/routes/ means this is not a Flex-style application skeleton — a
        // single-file kernel, say. Guessing where its routes live would write a file
        // nothing loads, which looks like success and is not.
        if (!is_dir(\dirname($path))) {
            $io->warning([
                'No config/routes/ directory, so the routes import was not written.',
                'Register these two routes yourself, or the app will boot and show nothing:',
                '  resource: \'@NativeDesktopBundle/src/Resources/config/routes.php\' (type: php)',
            ]);

            return;
        }

        $fs->dumpFile($path, <<<'YAML'
            # The two endpoints the runtime POSTs to: /_native/api/booted and
            # /_native/api/events. A Symfony bundle cannot register routes for itself, so this
            # import is what makes them exist — without it the app boots and then nothing ever
            # appears, because /booted 404s and AppBootstrapper::boot() is never called.
            native_desktop:
                resource: '@NativeDesktopBundle/src/Resources/config/routes.php'
                type: php

            YAML);

        $io->text(sprintf('Wrote %s', $path));
    }

    /** @param list<string> $args */
    private function runNpm(string $cwd, array $args, SymfonyStyle $io): int
    {
        $process = new Process(['npm', ...$args], $cwd, timeout: null);
        $exit = $process->run(function (string $type, string $buffer) use ($io): void {
            if ($io->isVerbose()) {
                $io->write($buffer);
            }
        });

        if (0 !== $exit) {
            $io->error(sprintf('npm %s failed (exit %d). Re-run with -v to see the output.', implode(' ', $args), $exit));
        }

        return 0 === $exit ? Command::SUCCESS : Command::FAILURE;
    }
}
