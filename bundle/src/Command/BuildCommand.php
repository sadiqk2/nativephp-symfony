<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Command;

use Native\Symfony\Desktop\Builder\Builder;
use Native\Symfony\Desktop\Support\Platform;
use Native\Symfony\Desktop\Updater\PublishTarget;
use Native\Symfony\Desktop\Support\ProjectPath;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Package the application for distribution.
 *
 * The pipeline mirrors upstream's: stage the app into $NATIVEPHP_BUILD_PATH/app,
 * reinstall without dev dependencies, strip the .env, install icons and a CA
 * bundle, then hand off to electron-builder — which copies the whole build
 * directory into the package as `extraResources`.
 *
 * **This produces a build with readable source.** Upstream's protected build needs
 * a bundle file from Bifrost, their hosted service, which is fetched per project
 * and used both as the `php -S` router and prepended to every CLI invocation.
 * Whether that bundler can target `bin/console` instead of `artisan` is not
 * answerable from the open-source client, so a Symfony app can only produce the
 * unprotected variant today. The warning below says so rather than letting anyone
 * ship source unknowingly.
 */
#[AsCommand(name: 'native:build', description: 'Package the app for distribution')]
final class BuildCommand extends Command
{
    private const OPERATING_SYSTEMS = ['linux', 'mac', 'win'];
    private const ARCHITECTURES = ['x64', 'arm64'];

    private readonly ProjectPath $paths;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly array $config,
        ?Platform $platform = null,
    ) {
        parent::__construct();

        $this->paths = new ProjectPath($projectDir, $platform);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('os', InputArgument::OPTIONAL, 'linux, mac or win (defaults to the current OS)')
            ->addArgument('arch', InputArgument::OPTIONAL, 'x64 or arm64', 'x64')
            ->addOption('electron-path', null, InputOption::VALUE_REQUIRED, 'Path to the Electron project', 'nativephp/electron')
            ->addOption('dir', null, InputOption::VALUE_NONE, 'Produce an unpacked directory instead of an installer — much faster for a smoke test')
            ->addOption('skip-composer', null, InputOption::VALUE_NONE, 'Reuse the staged vendor/ instead of reinstalling without dev dependencies')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish to the configured updater provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $os = $this->resolveOs($input->getArgument('os'));
        $arch = (string) $input->getArgument('arch');

        if (null === $os) {
            $io->error(sprintf('Unknown OS. Choose one of: %s.', implode(', ', self::OPERATING_SYSTEMS)));

            return Command::INVALID;
        }

        if (!\in_array($arch, self::ARCHITECTURES, true)) {
            $io->error(sprintf('Unknown architecture "%s". Choose one of: %s.', $arch, implode(', ', self::ARCHITECTURES)));

            return Command::INVALID;
        }

        $electron = $this->absolute((string) $input->getOption('electron-path'));

        if (!is_file($electron.'/package.json')) {
            $io->error(['No Electron project at '.$electron, 'Run native:install first.']);

            return Command::INVALID;
        }

        $buildPath = $this->projectDir.'/nativephp/build';
        $builder = $this->builder($buildPath);

        $io->warning([
            'This build will contain readable PHP source.',
            'Upstream\'s protected build needs a bundle from Bifrost (NativePHP\'s hosted service),',
            'which currently targets Laravel entry points.',
        ]);

        // -- hooks ---------------------------------------------------------------
        /** @var list<string> $prebuild */
        $prebuild = $this->config['prebuild'] ?? [];

        if ([] !== $prebuild) {
            $io->section('Pre-build hooks');

            if (!$builder->runHooks($prebuild, fn (string $b) => $io->write($b))) {
                $io->error('A pre-build command failed; stopping before anything is staged.');

                return Command::FAILURE;
            }
        }

        // -- stage ---------------------------------------------------------------
        $io->section('Staging the application');
        $copied = $builder->stageApplication(function (int $n) use ($io): void {
            if ($io->isVerbose()) {
                $io->write("\r  {$n} files…");
            }
        });
        $io->text(sprintf('Copied %d files to %s', $copied, $builder->appPath()));

        // Before composer, not after: its Flex auto-scripts (cache:clear,
        // assets:install) read the staged .env, so with APP_ENV still 'dev' they
        // warm a dev debug container into the var/cache that the exclude list
        // exists to keep out of the package — ~2MB of compiled service graph that
        // shipped in every build, while the prod cache it wants stayed cold.
        $io->section('Cleaning the environment file');
        $builder->cleanEnvironmentFile();

        if (!$input->getOption('skip-composer')) {
            $io->section('Installing production dependencies');

            if (!$builder->installProductionDependencies(fn (string $b) => $io->isVerbose() ? $io->write($b) : null)) {
                $io->error('composer install --no-dev failed in the build directory. Re-run with -v.');

                return Command::FAILURE;
            }
        }

        $this->warnAboutPackagedSecrets($builder, $io);

        $io->section('Installing the CA bundle and icons');

        if (!$builder->installCertificateAuthority()) {
            // Not fatal, but every outbound HTTPS call in the packaged app will fail.
            $io->warning([
                'No cacert.pem found (expected in vendor/nativephp/php-bin).',
                'The packaged app will have no CA bundle, so outbound TLS will fail.',
                'Install it with: composer require nativephp/php-bin',
            ]);
        }

        $icons = $builder->installIcons($electron);
        $io->text([] === $icons
            ? 'No icons in public/; electron-builder will use its default.'
            : 'Icons: '.implode(', ', $icons));

        // -- package -------------------------------------------------------------
        $io->section(sprintf('Packaging for %s-%s', $os, $arch));

        $exit = $this->runElectronBuilder($electron, $buildPath, $os, $arch, $input, $io);

        if (Command::SUCCESS !== $exit) {
            return $exit;
        }

        /** @var list<string> $postbuild */
        $postbuild = $this->config['postbuild'] ?? [];

        if ([] !== $postbuild) {
            $io->section('Post-build hooks');
            $builder->runHooks($postbuild, fn (string $b) => $io->write($b));
        }

        $io->success('Build complete: '.$electron.'/dist');

        return Command::SUCCESS;
    }

    private function runElectronBuilder(
        string $electron,
        string $buildPath,
        string $os,
        string $arch,
        InputInterface $input,
        SymfonyStyle $io,
    ): int {
        /** @var array<string, mixed> $updater */
        $updater = $this->config['updater'] ?? [];
        $publishTarget = PublishTarget::fromConfig($updater);

        $env = [
            // php.js reads these in beforeBuild to unzip the *target* platform's
            // static binary over whatever the dev machine put there.
            'APP_PATH' => $this->projectDir,
            'NATIVEPHP_BUILDING' => 'true',
            'NATIVEPHP_BUILD_PATH' => $buildPath,
            'NATIVEPHP_ELECTRON_PATH' => $electron,
            'NATIVEPHP_PHP_BINARY_PATH' => $this->projectDir.'/vendor/nativephp/php-bin/bin/',
            'NATIVEPHP_PHP_BINARY_VERSION' => \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION,
            'NATIVEPHP_APP_NAME' => (string) ($this->config['name'] ?? 'app'),
            'NATIVEPHP_APP_ID' => (string) ($this->config['app_id'] ?? 'com.example.app'),
            'NATIVEPHP_APP_VERSION' => (string) ($this->config['version'] ?? '1.0.0'),
            'NATIVEPHP_APP_AUTHOR' => (string) ($this->config['author'] ?? ''),
            'NATIVEPHP_APP_COPYRIGHT' => (string) ($this->config['copyright'] ?? ''),
            'NATIVEPHP_APP_FILENAME' => $this->slug((string) ($this->config['name'] ?? 'app')),
            'NATIVEPHP_DEEPLINK_SCHEME' => (string) ($this->config['deeplink_scheme'] ?? ''),
            // electron-builder assigns this straight to its own `publish` option, so it
            // has to be a publish provider — not the config tree the *runtime* reads from
            // native:config. And without the ENABLED flag the mjs drops `publish`
            // altogether, which is why --publish used to upload nothing at all.
            'NATIVEPHP_UPDATER_ENABLED' => null === $publishTarget ? 'false' : 'true',
            'NATIVEPHP_UPDATER_CONFIG' => json_encode($publishTarget?->builderOptions() ?? [], \JSON_THROW_ON_ERROR),
            'NATIVEPHP_NSIS_DELETE_APP_DATA' => ($this->config['nsis']['delete_app_data_on_uninstall'] ?? false) ? 'true' : 'false',
            'APP_URL' => (string) ($this->config['base_url'] ?? ''),
            // GH_TOKEN, AWS_* or DO_* — whatever the chosen provider uploads with.
            ...$publishTarget?->environmentVariables() ?? [],
        ];

        $this->patchPackageJson($electron, $io);

        if ($input->getOption('dir')) {
            // Skips installer generation entirely — proves the pipeline without
            // needing fpm, dpkg or an AppImage runtime in the environment.
            $command = ['npx', 'electron-vite', 'build'];

            if (Command::SUCCESS !== $this->runProcess($command, $electron, $env, $io)) {
                return Command::FAILURE;
            }

            $command = [
                'npx', 'electron-builder', '--config', 'electron-builder.mjs',
                '--'.$os, '--'.$arch, '--dir', '-p', 'never',
            ];

            return $this->runProcess($command, $electron, $env, $io);
        }

        $publish = (bool) $input->getOption('publish');
        $script = ($publish ? 'publish' : 'build').":{$os}-{$arch}";

        if ($this->electronDefines($electron, $script)) {
            return $this->runProcess(['npm', 'run', $script], $electron, $env, $io);
        }

        // Upstream's package.json has a script per platform pair, and Windows on ARM is
        // not one of them — so `native:build win arm64`, which this command accepts
        // without complaint, used to end at npm's "Missing script" after staging the
        // application and reinstalling its dependencies. electron-builder itself targets
        // that pair happily, and the scripts are only ever `electron-vite build` followed
        // by `electron-builder --<os> --<arch>`, so run the two directly instead.
        $io->note(sprintf('The Electron project defines no "%s" script; running electron-builder directly.', $script));

        if (Command::SUCCESS !== $this->runProcess(['npx', 'electron-vite', 'build'], $electron, $env, $io)) {
            return Command::FAILURE;
        }

        return $this->runProcess([
            'npx', 'electron-builder', '--config', 'electron-builder.mjs',
            '--'.$os, '--'.$arch, '-p', $publish ? 'always' : 'never',
        ], $electron, $env, $io);
    }

    /** Whether the installed Electron project has an npm script by that name. */
    private function electronDefines(string $electron, string $script): bool
    {
        $json = json_decode((string) @file_get_contents(Path::join($electron, 'package.json')), true);

        return \is_array($json) && \is_array($json['scripts'] ?? null) && isset($json['scripts'][$script]);
    }

    /**
     * electron-builder reads the app's identity from package.json, not from our
     * config, so it has to be written in before the build.
     */
    private function patchPackageJson(string $electron, SymfonyStyle $io): void
    {
        $path = Path::join($electron, 'package.json');
        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        $json['name'] = $this->slug((string) ($this->config['name'] ?? 'app'));
        $json['version'] = (string) ($this->config['version'] ?? '1.0.0');
        $json['description'] = (string) ($this->config['description'] ?? '');
        $json['author'] = (string) ($this->config['author'] ?? '');
        $json['homepage'] = (string) ($this->config['website'] ?? '');

        $encoded = json_encode($json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR).\PHP_EOL;

        // Reporting the new name and version without checking the write would describe a
        // package.json that is still upstream's — and the installer names the artifact
        // from this file, so the whole build would carry the wrong identity quietly.
        if (false === @file_put_contents($path, $encoded)) {
            throw new \RuntimeException(sprintf('Could not write %s. Check its permissions.', $path));
        }

        $io->text(sprintf('package.json: %s %s', $json['name'], $json['version']));
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private function runProcess(array $command, string $cwd, array $env, SymfonyStyle $io): int
    {
        $io->text('$ '.implode(' ', $command));

        $process = new Process($command, $cwd, $env, timeout: null);

        $exit = $process->run(static function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (0 !== $exit) {
            $io->error(sprintf('%s failed (exit %d).', $command[0], $exit));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resolveOs(?string $os): ?string
    {
        $os ??= match (\PHP_OS_FAMILY) {
            'Windows' => 'win',
            'Darwin' => 'mac',
            'Linux' => 'linux',
            default => null,
        };

        return \in_array($os, self::OPERATING_SYSTEMS, true) ? $os : null;
    }

    private function builder(string $buildPath): Builder
    {
        /** @var array{exclude: list<string>, keep: list<string>, env_defaults: array<string, string>, env_remove: list<string>, env_keep: list<string>} $build */
        $build = $this->config['build'];

        return new Builder(
            sourcePath: $this->projectDir,
            buildPath: $buildPath,
            excludePatterns: $build['exclude'],
            keepDirectories: $build['keep'],
            envDefaults: $build['env_defaults'],
            envRemove: $build['env_remove'],
            envKeep: $build['env_keep'],
        );
    }

    private function absolute(string $path): string
    {
        return $this->paths->absolute($path);
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? $value);

        return trim($slug, '-') ?: 'app';
    }

    /**
     * Say once, out loud, that a packaged desktop app cannot keep a secret.
     *
     * Symfony's secrets vault needs its decrypt key at runtime, so excluding the
     * key would break any app that uses the vault — this is a warning rather than
     * an exclusion on purpose. But the key ships inside a directory the user owns
     * and can read, which makes the vault decryptable by anyone holding the app.
     * That is true of every credential in a desktop package, and it is the sort of
     * thing worth being told before shipping rather than after.
     */
    private function warnAboutPackagedSecrets(Builder $builder, SymfonyStyle $io): void
    {
        $vaults = glob($builder->appPath('config/secrets/*/*.decrypt.private.php')) ?: [];

        if ([] === $vaults) {
            return;
        }

        $io->warning([
            sprintf('The secrets decrypt key is in the package (%d vault(s)).', \count($vaults)),
            'It has to be, or the vault cannot be read at runtime — but it means anyone with',
            'the app can decrypt every secret in it. Desktop packages cannot hold a secret from',
            'the person running them; keep anything that must stay secret on a server.',
        ]);
    }
}
