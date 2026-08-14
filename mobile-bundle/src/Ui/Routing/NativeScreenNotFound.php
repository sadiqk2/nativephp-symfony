<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * No declared native screen answers this path.
 *
 * Reachable in normal operation, not only through a bug: the device decides to boot native
 * from a *baked* pattern list that can be staler than the running code (a screen removed
 * since the last build, with the runtime dump's version no longer matching). Upstream's
 * runloop treats an unresolvable URI as "exit to web", and a caller here should have the
 * same option — hence a catchable exception rather than an assertion.
 */
final class NativeScreenNotFound extends \RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('No native screen is declared for path "%s".', $path));
    }
}
