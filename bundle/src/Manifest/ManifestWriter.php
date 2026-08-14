<?php

declare(strict_types=1);

namespace Native\Symfony\Manifest;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes `nativephp.json` into the application root.
 *
 * The application root, not the Electron project: the runtime resolves the
 * manifest against `getAppPath()`, which in development is `APP_PATH` (the Symfony
 * project) and in a packaged build is the staged copy of it. Putting the file
 * anywhere else means the runtime silently falls back to Laravel's defaults.
 *
 * It is generated, but it belongs in version control — the packaged app needs it,
 * and BuildCommand stages the project root wholesale rather than running console
 * commands inside the staging directory.
 */
final class ManifestWriter
{
    public const string FILENAME = 'nativephp.json';

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $projectDir,
        private readonly Manifest $manifest,
        ?Filesystem $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    public function path(): string
    {
        return rtrim($this->projectDir, '/').'/'.self::FILENAME;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return bool true if the file was created or its contents changed, false if
     *              it was already byte-identical
     *
     * @throws IOException when the project root is not writable
     */
    public function write(): bool
    {
        $json = $this->manifest->toJson();
        $path = $this->path();

        // Comparing before writing keeps re-runs out of `git status` and makes the
        // command's own report ("unchanged") truthful rather than optimistic.
        if (is_file($path) && file_get_contents($path) === $json) {
            return false;
        }

        $this->filesystem->dumpFile($path, $json);

        return true;
    }
}
