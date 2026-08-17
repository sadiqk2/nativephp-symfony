<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Security;

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
 * **Only where something else is actually guarding these paths.** Removing the
 * application's rules is only safe while the shared-secret gate is enforcing in
 * their place, so this hands over on exactly the condition that installs the
 * gate — not merely on `running`. Two states made the difference load bearing:
 * `block_browser_access: false` is a documented one-line option that removes the
 * gate entirely, and the gate deliberately fails open when the runtime supplied
 * no secret. In either, exempting these paths would leave an endpoint that
 * dispatches events by name with nothing in front of it at all.
 *
 * The exact two paths, not the prefix they share. The bundle registers exactly
 * two routes and knows both; a prefix also covers whatever else an application
 * happens to route under it, and a catch-all front-end route — `/{path}` with a
 * `.*` requirement, which is how every SPA is wired — silently loses its
 * access_control for that whole subtree inside the desktop app.
 */
final class RuntimeRoutesAccessMap implements AccessMapInterface
{
    /** Kept in step with Resources/config/routes.php. */
    private const EXEMPT = ['/_native/api/booted', '/_native/api/events'];

    public function __construct(
        private readonly AccessMapInterface $inner,
        private readonly bool $running,
        private readonly bool $gateEnabled,
        private readonly ?string $secret,
    ) {
    }

    public function getPatterns(Request $request): array
    {
        if ($this->guarded() && \in_array($request->getPathInfo(), self::EXEMPT, true)) {
            return [null, null];
        }

        return $this->inner->getPatterns($request);
    }

    /**
     * Whether RuntimeAccessSubscriber is both installed and actually enforcing.
     *
     * Mirrors its own early returns; see RuntimeAccessSubscriber::onKernelRequest.
     */
    private function guarded(): bool
    {
        return $this->running && $this->gateEnabled && null !== $this->secret && '' !== $this->secret;
    }
}
