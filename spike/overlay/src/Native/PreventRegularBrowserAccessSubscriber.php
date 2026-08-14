<?php

declare(strict_types=1);

namespace App\Native;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Port of Native\Desktop\Http\Middleware\PreventRegularBrowserAccess.
 *
 * The PHP dev server listens on a real loopback TCP port. This shared secret —
 * as either the _php_native cookie the runtime injects into the Electron
 * session, or the X-NativePHP-Secret header it attaches to every renderer
 * request — is the only thing keeping any other local browser or process out.
 * Not decoration.
 */
final class PreventRegularBrowserAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(env: 'default::NATIVEPHP_SECRET')]
        private readonly ?string $secret = null,
        #[Autowire(env: 'default::NATIVEPHP_RUNNING')]
        private readonly ?string $running = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 4096]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Outside the runtime this is an ordinary web app; do nothing.
        if (!filter_var($this->running ?? 'false', \FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (null === $this->secret || '' === $this->secret) {
            return;
        }

        $request = $event->getRequest();

        if (hash_equals($this->secret, (string) $request->cookies->get('_php_native'))) {
            return;
        }

        if (hash_equals($this->secret, (string) $request->headers->get('X-NativePHP-Secret'))) {
            return;
        }

        $event->setResponse(new Response('Forbidden', Response::HTTP_FORBIDDEN));
    }
}
