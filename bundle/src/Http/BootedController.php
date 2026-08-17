<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Http;

use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Event\App\ApplicationBooted;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Contract requirement 5. The runtime POSTs here at the end of its boot, and
 * again on macOS `activate` when no windows are visible.
 *
 * Must stay idempotent — WindowManager::open() is, so a bootstrapper that only
 * opens windows needs no guard of its own.
 */
final class BootedController
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?AppBootstrapper $bootstrapper = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(): JsonResponse
    {
        if (null === $this->bootstrapper) {
            // Without a bootstrapper the app boots to no windows at all and
            // looks like a hang. The runtime swallows non-2xx here, so say it
            // out loud in the log rather than only in the response body.
            $this->logger->warning(
                'The NativePHP runtime booted but no {contract} service is registered, so no window '.
                'will open. Implement it and the container will autowire it.',
                ['contract' => AppBootstrapper::class],
            );

            return new JsonResponse([
                'success' => false,
                'error' => sprintf('No %s service is registered.', AppBootstrapper::class),
            ], 500);
        }

        $this->bootstrapper->boot();

        $this->dispatcher->dispatch(new ApplicationBooted());

        return new JsonResponse(['success' => true]);
    }
}
