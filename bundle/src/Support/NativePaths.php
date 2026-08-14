<?php

declare(strict_types=1);

namespace Native\Symfony\Support;

/**
 * The ten OS locations the runtime pushes into the environment at boot, plus
 * storage, database and extras. No round-trip to the runtime needed.
 *
 * Laravel registers these as filesystem disks in configureDisks(). Symfony has
 * no global disk registry, so this exposes the raw paths and leaves Flysystem
 * out of the dependency graph — an app that wants disks can wire them from
 * these values in its own config.
 */
final class NativePaths
{
    /** @param array<string, string|null> $paths */
    public function __construct(private readonly array $paths)
    {
    }

    public function home(): ?string
    {
        return $this->paths['home'] ?? null;
    }

    public function appData(): ?string
    {
        return $this->paths['app_data'] ?? null;
    }

    public function userData(): ?string
    {
        return $this->paths['user_data'] ?? null;
    }

    public function desktop(): ?string
    {
        return $this->paths['desktop'] ?? null;
    }

    public function documents(): ?string
    {
        return $this->paths['documents'] ?? null;
    }

    public function downloads(): ?string
    {
        return $this->paths['downloads'] ?? null;
    }

    public function music(): ?string
    {
        return $this->paths['music'] ?? null;
    }

    public function pictures(): ?string
    {
        return $this->paths['pictures'] ?? null;
    }

    public function videos(): ?string
    {
        return $this->paths['videos'] ?? null;
    }

    public function recent(): ?string
    {
        return $this->paths['recent'] ?? null;
    }

    public function extras(): ?string
    {
        return $this->paths['extras'] ?? null;
    }

    public function storage(): ?string
    {
        return $this->paths['storage'] ?? null;
    }

    public function database(): ?string
    {
        return $this->paths['database'] ?? null;
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        return $this->paths;
    }
}
