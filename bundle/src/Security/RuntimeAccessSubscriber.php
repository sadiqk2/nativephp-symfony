<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Port of Laravel's PreventRegularBrowserAccess.
 *
 * Inside the runtime the app is served by PHP's built-in server on a real
 * loopback TCP port. The shared secret — as the _php_native cookie the runtime
 * injects into the Electron session, or the X-NativePHP-Secret header it
 * attaches to every renderer request via webRequest.onBeforeSendHeaders — is the
 * only thing keeping any other local browser or process out. This is load
 * bearing, not decoration.
 *
 * Priority is above Symfony's own firewall so the check runs before routing and
 * before any authentication listener.
 */
final class RuntimeAccessSubscriber implements EventSubscriberInterface
{
    /**
     * The prefix the runtime's own endpoints share.
     *
     * Lives here rather than on RuntimeRoutesAccessMap because that class implements
     * a security-http interface, so merely reading a constant from it fatals in an
     * app without the security bundle.
     */
    public const RUNTIME_PREFIX = '/_native/api/';

    public const COOKIE = '_php_native';
    public const HEADER = 'X-NativePHP-Secret';

    public function __construct(
        private readonly bool $running,
        private readonly ?string $secret,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4096]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Outside the runtime this is an ordinary web application. Do nothing.
        if (!$this->running) {
            return;
        }

        // Fail open rather than closed when the runtime gave us no secret: the
        // alternative is an app that 403s every request with no way to recover,
        // and a missing secret means the runtime itself is misconfigured.
        if (null === $this->secret || '' === $this->secret) {
            return;
        }

        $request = $event->getRequest();

        $cookie = $request->cookies->get(self::COOKIE);
        $header = $request->headers->get(self::HEADER);

        foreach ([$cookie, $header] as $candidate) {
            if (\is_string($candidate) && hash_equals($this->secret, $candidate)) {
                return;
            }
        }

        $event->setResponse(new Response('Forbidden', Response::HTTP_FORBIDDEN));
        $event->stopPropagation();
    }
}
