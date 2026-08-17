<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Manifest;

/**
 * Decides whether an installed Electron runtime reads `nativephp.json`.
 *
 * There is no version number to ask: the runtime is copied into the app as source
 * and rebuilt, and upstream patch 0001 adds no manifest of its own. So the only
 * honest signal is the code itself — does `php.ts` contain the manifest reader?
 *
 * That is a content sniff, and it is treated as one: anything less than every
 * marker present is reported as Unknown rather than guessed either way.
 */
final class ManifestSupportDetector
{
    /**
     * Markers that must all be present.
     *
     * Both survive TypeScript compilation, so the same set works on `php.ts` and on
     * the built `php.js`. The `AppManifest` interface name deliberately is *not* a
     * marker — interfaces are erased at build time, so it would report Supported for
     * sources and Unknown for the dist tree.
     */
    private const array MARKERS = ['nativephp.json', 'getManifest'];

    /**
     * Source first, and dist only when the source is unreadable.
     *
     * `native:install` rebuilds the plugin from source after patching, so the source
     * is what determines the runtime's behaviour. A dist tree that disagrees with its
     * own source is stale rather than authoritative.
     *
     * @var list<string>
     */
    private const array CANDIDATES = [
        'electron-plugin/src/server/php.ts',
        'electron-plugin/dist/server/php.js',
    ];

    public function detect(string $electronProjectPath): ManifestSupport
    {
        $source = $this->readFirstCandidate($electronProjectPath);

        if (null === $source) {
            return ManifestSupport::Unknown;
        }

        $hits = 0;

        foreach (self::MARKERS as $marker) {
            if (str_contains($source, $marker)) {
                ++$hits;
            }
        }

        return match ($hits) {
            \count(self::MARKERS) => ManifestSupport::Supported,
            0 => ManifestSupport::Absent,
            // Some but not all: a partially applied patch or an upstream rewrite.
            default => ManifestSupport::Unknown,
        };
    }

    private function readFirstCandidate(string $electronProjectPath): ?string
    {
        $root = rtrim($electronProjectPath, '/');

        foreach (self::CANDIDATES as $candidate) {
            $path = $root.'/'.$candidate;

            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $contents = file_get_contents($path);

            if (false !== $contents) {
                return $contents;
            }
        }

        return null;
    }
}
