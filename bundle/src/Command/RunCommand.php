<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Native\Symfony\Desktop\Support\Platform;
use Native\Symfony\Desktop\Support\ProjectPath;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Starts the desktop app in development.
 *
 * Mirrors what Laravel's ExecuteCommand trait does: set the environment the
 * Electron project expects, then run its dev script from the project directory.
 * In dev the runtime reads APP_PATH to find the app, so the Electron project and
 * the PHP app can live in different directories.
 *
 * `npm run dev` is `node php.js && electron-vite dev --watch`, and php.js exists
 * only to unzip a static binary out of the nativephp/php-bin composer package
 * into $NATIVEPHP_BUILD_PATH/php/php. With --php-binary we place a binary there
 * ourselves and skip php.js, which drops the php-bin dependency for development
 * entirely.
 */
#[AsCommand(name: 'native:run', description: 'Start the desktop app in development')]
final class RunCommand extends Command
{
    private readonly ProjectPath $paths;

    public function __construct(
        private readonly string $projectDir,
        ?Platform $platform = null,
    ) {
        parent::__construct();

        $this->paths = new ProjectPath($projectDir, $platform);
    }

    protected function configure(): void
    {
        $this
            ->addOption('php-binary', null, InputOption::VALUE_REQUIRED,
                'PHP binary for the runtime to use (defaults to the current one)')
            ->addOption('electron-path', null, InputOption::VALUE_REQUIRED,
                'Path to the Electron project', 'nativephp/electron')
            ->addOption('no-focus', null, InputOption::VALUE_NONE,
                'Do not steal focus when the app restarts on a file change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $electron = $this->absolute((string) $input->getOption('electron-path'));

        if (!is_file($electron.'/package.json')) {
            $io->error([
                sprintf('No Electron project at %s.', $electron),
                'Run bin/console native:install --source=... first.',
            ]);

            return Command::INVALID;
        }

        if (!is_dir($electron.'/node_modules')) {
            $io->error('The Electron project has no node_modules. Run native:install without --skip-npm.');

            return Command::INVALID;
        }

        $buildPath = $this->projectDir.'/nativephp/build';
        $php = $input->getOption('php-binary');
        $php = \is_string($php) && '' !== $php ? $php : (\PHP_BINARY ?: 'php');

        if (Command::SUCCESS !== $this->prepareBuildPath($buildPath, $php, $electron, $io)) {
            return Command::FAILURE;
        }

        $io->text(sprintf('App:      %s', $this->projectDir));
        $io->text(sprintf('Runtime:  %s', $electron));
        $io->text(sprintf('PHP:      %s', $php));
        $io->newLine();

        // electron-vite reads .env.development, which maps
        // MAIN_VITE_NATIVEPHP_BUILD_PATH from NATIVEPHP_BUILD_PATH; the main
        // process then derives the icon, CA cert and PHP binary paths from it.
        $env = [
            'APP_PATH' => $this->projectDir,
            'NATIVEPHP_BUILD_PATH' => $buildPath,
            'NATIVEPHP_ELECTRON_PATH' => $electron,
            'NATIVEPHP_PHP_BINARY_PATH' => \dirname($php).'/',
            'NATIVEPHP_PHP_BINARY_VERSION' => \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION,
            'NATIVEPHP_BUILDING' => 'false',
            'NODE_ENV' => 'development',
        ];

        if ($input->getOption('no-focus')) {
            $env['NATIVEPHP_NO_FOCUS'] = '1';
        }

        if ($output->isVerbose()) {
            // php.ts logs every PHP command it spawns above verbosity 0 — the
            // fastest way to see what the runtime is actually asking PHP to do.
            $env['SHELL_VERBOSITY'] = '1';
        }

        $process = new Process(['npx', 'electron-vite', 'dev'], $electron, $env, timeout: null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        return 0 === $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        }) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * $NATIVEPHP_BUILD_PATH needs exactly three things: an icon, a CA bundle and
     * a PHP binary. Laravel gets the first two from the desktop package's
     * resources/build; we take them from the installed Electron project, which
     * carries its own build/icon.png.
     */
    private function prepareBuildPath(string $buildPath, string $php, string $electron, SymfonyStyle $io): int
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $fs->mkdir($buildPath.'/php');

        if (!is_file($buildPath.'/icon.png')) {
            foreach ([$electron.'/build/icon.png', $electron.'/../build/icon.png'] as $candidate) {
                if (is_file($candidate)) {
                    $fs->copy($candidate, $buildPath.'/icon.png');
                    break;
                }
            }
        }

        if (!is_file($buildPath.'/icon.png')) {
            $io->error(sprintf('No icon at %s/icon.png and none found in the Electron project.', $buildPath));

            return Command::FAILURE;
        }

        if (!is_file($buildPath.'/cacert.pem')) {
            // The runtime passes this to every PHP process as curl.cainfo and
            // openssl.cafile, so an app's outbound TLS depends on it.
            // php-bin's own bundle first — it is the one the packaged app will use
            // (Builder::installCertificateAuthority stages exactly this), so dev and
            // production agree. Then the inis, which are commented out in both stock
            // php.ini files and unset by Homebrew, since OpenSSL finds its store at
            // build time rather than through PHP. Only then the platform locations:
            // the single Debian path that used to be the whole fallback exists on
            // neither macOS nor Windows, so `native:run` hard-failed before doing
            // anything else on the platform most Electron development happens on.
            $bundle = null;

            $candidates = [
                $this->projectDir.'/vendor/nativephp/php-bin/cacert.pem',
                ini_get('openssl.cafile') ?: null,
                ini_get('curl.cainfo') ?: null,
                ...match (\PHP_OS_FAMILY) {
                    'Darwin' => ['/etc/ssl/cert.pem', '/opt/homebrew/etc/ca-certificates/cert.pem', '/usr/local/etc/openssl@3/cert.pem'],
                    'Windows' => [],
                    default => ['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'],
                },
            ];

            foreach ($candidates as $candidate) {
                if (\is_string($candidate) && '' !== $candidate && is_file($candidate)) {
                    $bundle = $candidate;

                    break;
                }
            }

            if (null === $bundle) {
                $io->error([
                    sprintf('Could not find a CA bundle to install at %s/cacert.pem.', $buildPath),
                    'Install one with: composer require nativephp/php-bin (it ships cacert.pem),',
                    'or set openssl.cafile in your php.ini, or copy a bundle there yourself.',
                ]);

                return Command::FAILURE;
            }

            $fs->copy($bundle, $buildPath.'/cacert.pem');
        }

        $target = $buildPath.'/php/'.(\PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');

        if (!is_file($target) || filemtime($target) < filemtime($php)) {
            $fs->copy($php, $target, true);
            $fs->chmod($target, 0o755);
        }

        return Command::SUCCESS;
    }

    private function absolute(string $path): string
    {
        return $this->paths->absolute($path);
    }
}
