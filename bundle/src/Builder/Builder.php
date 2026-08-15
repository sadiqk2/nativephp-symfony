<?php

declare(strict_types=1);

namespace Native\Symfony\Builder;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Stages a Symfony application for packaging.
 *
 * electron-builder copies whatever is at $NATIVEPHP_BUILD_PATH into the packaged
 * app's resources/build (see the `extraResources` entry in electron-builder.mjs),
 * so "building" means assembling that directory: the app under app/, the target
 * platform's PHP binary under php/, a CA bundle, and the icons.
 *
 * The Symfony-specific parts are which files to exclude and which directories must
 * survive: Laravel keeps storage/framework/*, Symfony needs var/cache and var/log.
 */
final class Builder
{
    private readonly Filesystem $fs;

    /**
     * @param list<string>          $excludePatterns fnmatch patterns, relative to the source root
     * @param list<string>          $keepDirectories directories that must exist in the build
     * @param array<string, string> $envDefaults     .env values forced for production
     * @param list<string>          $envRemove       .env keys stripped (fnmatch)
     * @param list<string>          $envKeep         .env keys never stripped, whatever
     *                                               $envRemove says (fnmatch)
     */
    public function __construct(
        private readonly string $sourcePath,
        private readonly string $buildPath,
        private readonly array $excludePatterns = [],
        private readonly array $keepDirectories = [],
        private readonly array $envDefaults = [],
        private readonly array $envRemove = [],
        private readonly array $envKeep = [],
    ) {
        $this->fs = new Filesystem();
    }

    public function sourcePath(string $path = ''): string
    {
        return '' === $path ? $this->sourcePath : Path::join($this->sourcePath, $path);
    }

    public function buildPath(string $path = ''): string
    {
        return '' === $path ? $this->buildPath : Path::join($this->buildPath, $path);
    }

    public function appPath(string $path = ''): string
    {
        return $this->buildPath(Path::join('app', $path));
    }

    /**
     * Copy the application into the build directory, skipping excluded paths.
     *
     * Walks the tree rather than mirroring and deleting afterwards, so an excluded
     * directory is never copied at all — which matters when node_modules or a
     * vendor tree would take longer to copy than the rest of the build.
     *
     * @return int Number of files copied
     */
    public function stageApplication(?callable $onProgress = null): int
    {
        $this->fs->remove($this->appPath());
        $this->fs->mkdir($this->appPath());

        $copied = 0;
        $source = $this->sourcePath();

        $directories = new \RecursiveDirectoryIterator(
            $source,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
        );

        // FOLLOW_SYMLINKS has no cycle protection of its own: a link that points at
        // one of its own ancestors — `public/storage -> ..` is the shape people
        // actually write — is walked again every time it is reached, and the tree is
        // copied over and over until the paths grow long enough that opendir() fails.
        // The iterator then stops silently, so the failure is a package that is
        // quietly many times too large rather than an error. Remember which real
        // directories have been entered and refuse to enter one twice.
        $seen = [];

        $filtered = new \RecursiveCallbackFilterIterator(
            $directories,
            function (\SplFileInfo $current) use (&$seen): bool {
                if ($this->isExcluded($this->relative($current->getPathname()))) {
                    return false;
                }

                if (!$current->isDir()) {
                    return true;
                }

                $real = realpath($current->getPathname());

                if (false === $real || isset($seen[$real])) {
                    return false;
                }

                $seen[$real] = true;

                return true;
            },
        );

        foreach (new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            /** @var \SplFileInfo $item */
            $target = Path::join($this->appPath(), $this->relative($item->getPathname()));

            if ($item->isDir()) {
                $this->fs->mkdir($target);

                continue;
            }

            $this->fs->copy($item->getPathname(), $target, true);
            // Preserve the executable bit — bin/console has to stay runnable.
            if ('Windows' !== \PHP_OS_FAMILY) {
                $this->fs->chmod($target, fileperms($item->getPathname()) & 0o777);
            }

            ++$copied;

            if (null !== $onProgress && 0 === $copied % 500) {
                $onProgress($copied);
            }
        }

        $this->keepRequiredDirectories();

        return $copied;
    }

    /**
     * electron-builder prunes empty directories out of the package, and dotfiles do
     * not stop it, so each one gets a placeholder. Symfony will not boot without a
     * writable var/cache and var/log.
     */
    public function keepRequiredDirectories(): void
    {
        foreach ($this->keepDirectories as $directory) {
            $this->fs->dumpFile(Path::join($this->appPath(), $directory, '.nativephp-keep'), '');
        }
    }

    /**
     * Reinstall dependencies without dev packages.
     *
     * Runs in the build directory, so the developer's own vendor/ is untouched.
     */
    public function installProductionDependencies(?callable $onOutput = null): bool
    {
        $process = new Process(
            ['composer', 'install', '--no-dev', '--no-interaction', '--optimize-autoloader', '--no-progress'],
            $this->appPath(),
            timeout: 600,
        );

        $exit = $process->run(static function (string $type, string $buffer) use ($onOutput): void {
            if (null !== $onOutput) {
                $onOutput($buffer);
            }
        });

        if (0 !== $exit) {
            return false;
        }

        // php-bin is tens of megabytes of binaries for every platform; only the one
        // the runtime unzipped is needed, and that lives outside app/.
        $this->fs->remove([
            $this->appPath('vendor/nativephp/php-bin'),
            $this->appPath('vendor/bin'),
        ]);

        return true;
    }

    /**
     * Strip secrets from the staged .env and force production logging.
     *
     * Only the copy under app/ is touched — the developer's .env is never modified.
     */
    public function cleanEnvironmentFile(): void
    {
        $envPath = $this->appPath('.env');

        if (!is_file($envPath)) {
            return;
        }

        $kept = [];

        foreach (file($envPath, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trimmed = trim($line);

            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $key = strstr($trimmed, '=', true);

            if (false === $key) {
                continue;
            }

            // The keep list wins over the remove list on purpose. A broad glob like
            // *_SECRET is a reasonable thing for someone to add, and it matches
            // Symfony's own APP_SECRET — which framework.yaml reads as
            // %env(APP_SECRET)%, so stripping it makes the packaged app fail to
            // boot at all. That must not be something a user can do to themselves
            // by accident.
            if ($this->matchesAny($key, $this->envKeep)) {
                $kept[] = $trimmed;

                continue;
            }

            if ($this->matchesAny($key, $this->envRemove) || \array_key_exists($key, $this->envDefaults)) {
                continue;
            }

            $kept[] = $trimmed;
        }

        foreach ($this->envDefaults as $key => $value) {
            $kept[] = "{$key}={$value}";
        }

        $this->fs->dumpFile($envPath, implode("\n", $kept)."\n");
    }

    /**
     * The CA bundle the runtime passes to every PHP process as curl.cainfo and
     * openssl.cafile. Without it the packaged app has no working outbound TLS.
     */
    public function installCertificateAuthority(?string $source = null): bool
    {
        $source ??= $this->sourcePath('vendor/nativephp/php-bin/cacert.pem');

        if (!is_file($source)) {
            return false;
        }

        $this->fs->copy($source, $this->buildPath('cacert.pem'), true);

        return true;
    }

    /**
     * Icons, from public/ into both the build directory and the Electron project's
     * buildResources (where electron-builder looks for icon.png / .ico / .icns).
     *
     * @return list<string> The icon basenames that were found and copied
     */
    public function installIcons(string $electronPath): array
    {
        $installed = [];

        foreach (['icon.png', 'icon.ico', 'icon.icns', 'IconTemplate.png', 'IconTemplate@2x.png'] as $icon) {
            $source = $this->sourcePath('public/'.$icon);

            if (!is_file($source)) {
                continue;
            }

            $this->fs->copy($source, $this->buildPath($icon), true);

            if (str_starts_with($icon, 'icon.')) {
                $this->fs->copy($source, Path::join($electronPath, 'build', $icon), true);
            }

            $installed[] = $icon;
        }

        return $installed;
    }

    /** @param list<string> $commands */
    public function runHooks(array $commands, ?callable $onOutput = null): bool
    {
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command, $this->sourcePath(), timeout: 600);

            $exit = $process->run(static function (string $type, string $buffer) use ($onOutput): void {
                if (null !== $onOutput) {
                    $onOutput($buffer);
                }
            });

            if (0 !== $exit) {
                return false;
            }
        }

        return true;
    }

    private function relative(string $absolute): string
    {
        return str_replace('\\', '/', substr($absolute, \strlen($this->sourcePath()) + 1));
    }

    private function isExcluded(string $relativePath): bool
    {
        return $this->matchesAny($relativePath, $this->excludePatterns);
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }
}
