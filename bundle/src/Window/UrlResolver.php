<?php

declare(strict_types=1);

namespace Native\Symfony\Window;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Window URLs must be absolute — the runtime hands them straight to
 * BrowserWindow::loadURL(). Inside the runtime the app is served by PHP's
 * built-in server on a port picked at boot (8100-9000), so the host is only
 * knowable at request time.
 */
final class UrlResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ?string $fallbackBase = null,
    ) {
    }

    public function absolute(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->base(), '/').'/'.ltrim($path, '/');
    }

    private function base(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request) {
            return $request->getSchemeAndHttpHost();
        }

        if (null !== $this->fallbackBase && '' !== $this->fallbackBase) {
            return $this->fallbackBase;
        }

        // Outside a request (a console command, a queue worker) there is no way
        // to learn the dev server's port, which the runtime never publishes to
        // PHP. Say so rather than guessing a port and failing opaquely later.
        throw new \LogicException(
            'Cannot build an absolute window URL outside an HTTP request: the PHP server port is '.
            'assigned by the runtime at boot and is not exposed to spawned processes. Pass an absolute '.
            'URL, or set native_desktop.base_url.',
        );
    }
}
