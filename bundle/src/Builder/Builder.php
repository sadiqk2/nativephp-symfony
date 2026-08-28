<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Builder;

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
    /** @var list<string> */
    private readonly array $excludePatterns;

    public function __construct(
        private readonly string $sourcePath,
        private readonly string $buildPath,
        array $excludePatterns = [],
        private readonly array $keepDirectories = [],
        private readonly array $envDefaults = [],
        private readonly array $envRemove = [],
        private readonly array $envKeep = [],
    ) {
        $this->fs = new Filesystem();

        // The build directory conventionally lives at nativephp/build, inside the source
        // tree, and the shipped config excludes `nativephp` — but that is a *default*, and
        // `native_desktop.build.exclude` replaces the list rather than adding to it. An
        // application that sets its own exclude list therefore staged the previous build
        // into the new one: unbounded on the second run, and invisible on the first, when
        // the directory is still empty. The mobile builder guards this in the class; this
        // one trusted the configuration. Excluded by path now, whatever the list says.
        $nested = $this->relativeToSource($buildPath);
        $this->excludePatterns = null === $nested ? $excludePatterns : [...$excludePatterns, $nested, $nested.'/*'];
    }

    /** @return list<string> */
    public function excludePatterns(): array
    {
        return $this->excludePatterns;
    }

    /** The build path expressed relative to the source, or null when it is outside it. */
    private function relativeToSource(string $path): ?string
    {
        $source = rtrim(str_replace('\\', '/', $this->sourcePath), '/').'/';
        $candidate = str_replace('\\', '/', $path);

        return str_starts_with($candidate, $source) ? trim(substr($candidate, \strlen($source)), '/') : null;
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

        $filtered = new \RecursiveCallbackFilterIterator(
            $directories,
            function (\SplFileInfo $current): bool {
                if ($this->isExcluded($this->relative($current->getPathname()))) {
                    return false;
                }

                if ($current->isDir()) {
                    return !$this->closesASymlinkCycle($current->getPathname());
                }

                // A dangling symlink reports neither isDir() nor isFile(), and
                // copy() on one throws — which aborted the whole build over a link
                // whose target someone deleted months ago.
                return $current->isFile();
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
     * Whether entering this directory would re-enter one already on the path to it.
     *
     * `FOLLOW_SYMLINKS` has no cycle protection of its own, and a link pointing at
     * one of its own ancestors — `public/storage -> ..`, which people do write —
     * is otherwise walked again every time it is reached, until the paths grow long
     * enough that `copy()` fails on a name forty levels deep.
     *
     * Comparing against the *ancestor chain* rather than a set of every directory
     * already visited is the whole point. A global set also collapses two paths that
     * legitimately resolve to the same place, and `assets:install --symlink` — the
     * Flex default — produces exactly that: `public/bundles/acme -> ../../vendor/...`.
     * Skipping the loser there drops every bundle asset from the package while the
     * build still reports success, and which side loses is readdir order.
     *
     * Walking up the ancestors also catches mutual cycles (a -> b, b -> a), which a
     * simple "is my target my own parent" test does not: the repeated directory
     * always reappears as an ancestor of itself somewhere along the path.
     */
    private function closesASymlinkCycle(string $path): bool
    {
        $real = realpath($path);

        if (false === $real) {
            return true;
        }

        $ancestor = \dirname($path);
        $stop = \dirname($this->sourcePath());

        while ($ancestor !== $stop && '/' !== $ancestor && '.' !== $ancestor) {
            if (realpath($ancestor) === $real) {
                return true;
            }

            $parent = \dirname($ancestor);

            if ($parent === $ancestor) {
                break;
            }

            $ancestor = $parent;
        }

        return false;
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
            // Belt and braces with the .env cleaning that now happens first: Flex's
            // auto-scripts inherit this environment, and an app with no .env at all
            // would otherwise still warm a dev cache here.
            ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'] + getenv(),
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

        // No .env is not "nothing to do": the defaults are advertised as forced, and
        // an app that keeps its configuration elsewhere still needs APP_ENV=prod in
        // the package. Writing the file is also what makes the forcing observable.
        if (!is_file($envPath)) {
            if ([] !== $this->envDefaults) {
                $this->fs->dumpFile($envPath, $this->defaultsBlock());
            }

            return;
        }

        $kept = [];
        $continuation = null;
        $appending = false;

        foreach (file($envPath, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            // A quoted value may span lines — a PEM key, a JWT passphrase, a service
            // account JSON. Splitting on newlines and dropping every line without an
            // `=` truncated those at the first line and left the quote open, so the
            // staged .env no longer parsed and the packaged app died in bootEnv()
            // before the kernel existed, with the build reporting success.
            //
            // The continuation is tracked for *every* entry, not only the ones being
            // kept: a dropped entry's remaining lines are still its value, and left to
            // be read as fresh lines any of them containing an `=` — which base64
            // padding and JSON both produce — looked like a key/value pair and was
            // written into the staged file. The secret the remove list existed to strip
            // then shipped inside the package anyway, in pieces.
            if (null !== $continuation) {
                if ($appending) {
                    $kept[array_key_last($kept)] .= "\n".$line;
                }

                if ($this->closesQuote($line, $continuation)) {
                    $continuation = null;
                    $appending = false;
                }

                continue;
            }

            $trimmed = trim($line);

            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $key = strstr($trimmed, '=', true);

            if (false === $key) {
                continue;
            }

            // `export FOO=bar` is valid in Symfony's Dotenv, and taking the key as
            // everything before the `=` yielded "export FOO". fnmatch is anchored at
            // both ends, so that broke the match in *both* directions: a prefix glob
            // like STRIPE_* stopped matching and the secret shipped, while a leading-
            // star glob like *_SECRET still matched and stripped an APP_SECRET the
            // keep list was supposed to protect.
            $key = preg_replace('/^export\s+/', '', $key) ?? $key;
            $key = trim($key);

            // The keep list wins over the remove list on purpose. A broad glob like
            // *_SECRET is a reasonable thing for someone to add, and it matches
            // Symfony's own APP_SECRET — which framework.yaml reads as
            // %env(APP_SECRET)%, so stripping it makes the packaged app fail to
            // boot at all. That must not be something a user can do to themselves
            // by accident.
            $keep = $this->matchesAny($key, $this->envKeep)
                || !($this->matchesAny($key, $this->envRemove) || \array_key_exists($key, $this->envDefaults));

            if ($keep) {
                $kept[] = $trimmed;
            }

            // Whatever quote this value opened has to be tracked to the line that closes
            // it — to carry the rest of a kept value, and to swallow the rest of a
            // dropped one.
            $quote = $this->opensQuote(substr($trimmed, \strlen((string) strstr($trimmed, '=', true)) + 1));

            if (null !== $quote) {
                $continuation = $quote;
                $appending = $keep;
            }
        }

        foreach ($this->envDefaults as $key => $value) {
            $kept[] = "{$key}={$value}";
        }

        $this->fs->dumpFile($envPath, implode("\n", $kept)."\n");
    }

    private function defaultsBlock(): string
    {
        $lines = [];

        foreach ($this->envDefaults as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The quote character a value opens and does not close on its own line, if any.
     */
    private function opensQuote(string $value): ?string
    {
        $value = ltrim($value);
        $quote = substr($value, 0, 1);

        if ('"' !== $quote && "'" !== $quote) {
            return null;
        }

        return $this->closesQuote(substr($value, 1), $quote) ? null : $quote;
    }

    /**
     * Whether this text leaves an open quote closed — parity, not first occurrence.
     *
     * "Contains a quote" is the obvious rule and it is wrong for exactly the values that
     * need multi-line handling in the first place. A service-account JSON continues with
     * lines like `  "key": "sk_live_…",` whose *first* quote is an opening one; treating it
     * as the close ended the value early, and every line after it was read as a fresh
     * entry. Counting instead means a line closes the value only if it has an odd number of
     * unescaped quotes, so `}"` ends it and `"key": "value",` does not.
     */
    private function closesQuote(string $text, string $quote): bool
    {
        $seen = 0;

        for ($i = 0, $length = \strlen($text); $i < $length; ++$i) {
            if ('\\' === $text[$i]) {
                ++$i;

                continue;
            }

            if ($text[$i] === $quote) {
                ++$seen;
            }
        }

        return 1 === $seen % 2;
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
     * An app that ships none of its own still needs them there. Upstream's build path is
     * not assembled from nothing: `resources/build/.gitignore` whitelists exactly
     * `icon.png`, `IconTemplate.png` and `IconTemplate@2x.png`, so every packaged Laravel
     * app carries all three and `installIcon()` only overrides them. Ours started empty,
     * and the packaged runtime opens `$NATIVEPHP_BUILD_PATH/icon.png` for the dock and the
     * Linux window icon, and the same path with `icon.png` swapped for `IconTemplate.png`
     * for the tray `MenuBar::create()` builds when given no icon of its own — which
     * Electron refuses to load when the file is not there. So the runtime's own copies,
     * which `native:install` keeps in the Electron project's build/, are the fallback.
     *
     * @return list<string> The icon basenames that were found and copied
     */
    public function installIcons(string $electronPath): array
    {
        $installed = [];

        foreach (['icon.png', 'icon.ico', 'icon.icns', 'IconTemplate.png', 'IconTemplate@2x.png'] as $icon) {
            $own = $this->sourcePath('public/'.$icon);
            $source = is_file($own) ? $own : Path::join($electronPath, 'build', $icon);

            if (!is_file($source)) {
                continue;
            }

            $this->fs->copy($source, $this->buildPath($icon), true);

            // Only the app's own goes back into buildResources — the runtime's default
            // is already there, and that is the file this just read.
            if ($source === $own && str_starts_with($icon, 'icon.')) {
                $this->fs->copy($own, Path::join($electronPath, 'build', $icon), true);
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
