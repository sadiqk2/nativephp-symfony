<?php

declare(strict_types=1);

namespace Native\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Keeps the application's `access_control` off the runtime's own endpoints.
 *
 * The problem: {@see RuntimeAccessSubscriber} runs at priority 4096 but returns
 * without stopping propagation — it cannot do otherwise, since the router listens
 * at 32 and an application may have listeners of its own below the firewall. So
 * the firewall still evaluates, and a rule of `^/` — what most authenticated apps
 * have — answers the runtime's `POST /_native/api/booted` with a 401 that the
 * runtime discards. The app boots to a window that never does anything, and
 * nothing anywhere says why. Laravel never had to solve this: there the two
 * routes are registered outside the app's middleware groups entirely.
 *
 * Why a decorator, of all things: Symfony's security config is single-source, so
 * a bundle cannot contribute the fix as configuration. `security.firewalls`
 * rejects keys from a second config file outright, and `security.access_control`
 * throws ForbiddenOverwriteException. Decorating the access map is what is left,
 * and it is also the narrowest thing that works — the request pipeline is
 * untouched, and every other path still resolves exactly as configured.
 *
 * **Only inside the runtime.** The same codebase deployed as an ordinary web
 * application must keep its firewall over these paths: there `running` is false,
 * so RuntimeAccessSubscriber lets everything through and the application's own
 * rules are the only thing standing in front of an endpoint that dispatches
 * events by name. Neutralising them there would be a hole rather than a fix.
 */
final class RuntimeRoutesAccessMap implements AccessMapInterface
{
    public function __construct(
        private readonly AccessMapInterface $inner,
        private readonly bool $running,
    ) {
    }

    public function getPatterns(Request $request): array
    {
        if ($this->running && str_starts_with($request->getPathInfo(), RuntimeAccessSubscriber::RUNTIME_PREFIX)) {
            return [null, null];
        }

        return $this->inner->getPatterns($request);
    }
}
