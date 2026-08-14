<?php

declare(strict_types=1);

namespace App\Native;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A slice of the Window API — enough to prove the contract works from Symfony.
 * Full surface is 21 endpoints (CONTRACT.md section 1).
 */
final class WindowManager
{
    public function __construct(
        private readonly Client $client,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * window/open is idempotent by id: an existing id is shown+focused and
     * nothing new is created, so calling this from the booted handler is safe
     * even though the runtime may call /booted more than once (macOS activate).
     */
    public function open(string $id = 'main', string $url = '/', int $width = 1000, int $height = 700, array $extra = []): void
    {
        $this->client->post('window/open', [
            'id' => $id,
            'url' => $this->absolute($url),
            'width' => $width,
            'height' => $height,
            'title' => 'NativePHP for Symfony',
            'resizable' => true,
            'zoomFactor' => 1.0,
            'showDevTools' => false,
            ...$extra,
        ])->getStatusCode();
    }

    public function resize(int $width, int $height, ?string $id = null): void
    {
        $this->client->post('window/resize', [
            'id' => $id ?? $this->detectId() ?? 'main',
            'width' => $width,
            'height' => $height,
        ])->getStatusCode();
    }

    public function title(string $title, ?string $id = null): void
    {
        $this->client->post('window/title', [
            'id' => $id ?? $this->detectId() ?? 'main',
            'title' => $title,
        ])->getStatusCode();
    }

    /** @return array<string, mixed> */
    public function get(string $id = 'main'): array
    {
        return $this->client->get("window/get/{$id}")->toArray(false);
    }

    /**
     * Port of Native\Desktop\Concerns\DetectsWindowId: the runtime appends
     * ?_windowId=<id> to every navigation it performs, and the current
     * request's window is recovered from the Referer first, falling back to
     * the current URL. Keep that order — see ANALYSIS.md section 7.5.
     */
    private function detectId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        foreach ([$request->headers->get('Referer'), $request->getUri()] as $candidate) {
            if (null === $candidate) {
                continue;
            }

            parse_str((string) parse_url($candidate, \PHP_URL_QUERY), $query);

            if (isset($query['_windowId']) && '' !== $query['_windowId']) {
                return (string) $query['_windowId'];
            }
        }

        return null;
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $request = $this->requestStack->getCurrentRequest();
        $base = null !== $request
            ? $request->getSchemeAndHttpHost()
            : 'http://127.0.0.1:'.($_SERVER['SERVER_PORT'] ?? '8000');

        return $base.'/'.ltrim($path, '/');
    }
}
