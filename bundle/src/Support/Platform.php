<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Support;

/**
 * Which OS the app is running on.
 *
 * A service rather than static calls on PHP_OS_FAMILY, so tests can exercise the
 * macOS-only dock paths on Linux CI.
 */
class Platform
{
    public function __construct(private readonly string $family = \PHP_OS_FAMILY)
    {
    }

    public function isMac(): bool
    {
        return 'Darwin' === $this->family;
    }

    public function isWindows(): bool
    {
        return 'Windows' === $this->family;
    }

    public function isLinux(): bool
    {
        return 'Linux' === $this->family;
    }

    public function family(): string
    {
        return $this->family;
    }
}
