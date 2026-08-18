<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Window;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * All 21 window endpoints (CONTRACT.md §1).
 *
 * Every mutation takes an optional $id and falls back to the window the current
 * request came from. Unknown ids are silently ignored by the runtime — it uses
 * optional chaining on its window map — so a typo'd id is a no-op, not an error.
 */
final class WindowManager
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlResolver $urls,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function open(string $id = 'main'): PendingWindow
    {
        return new PendingWindow($this->client, $this->urls, $id);
    }

    public function close(?string $id = null): void
    {
        $this->client->post('window/close', ['id' => $this->resolveId($id)]);
    }

    public function show(?string $id = null): void
    {
        $this->client->post('window/show', ['id' => $this->resolveId($id)]);
    }

    public function hide(?string $id = null): void
    {
        $this->client->post('window/hide', ['id' => $this->resolveId($id)]);
    }

    public function maximize(?string $id = null): void
    {
        $this->client->post('window/maximize', ['id' => $this->resolveId($id)]);
    }

    public function unmaximize(?string $id = null): void
    {
        $this->client->post('window/unmaximize', ['id' => $this->resolveId($id)]);
    }

    public function minimize(?string $id = null): void
    {
        $this->client->post('window/minimize', ['id' => $this->resolveId($id)]);
    }

    public function reload(?string $id = null): void
    {
        $this->client->post('window/reload', ['id' => $this->resolveId($id)]);
    }

    /**
     * Note: this does NOT produce a WindowResized event. The runtime listens for
     * Electron's `resized`, which fires on user-driven resizes only, not on
     * setSize(). Verified empirically — see SPIKE-RESULTS.md finding 5.
     */
    public function resize(int $width, int $height, ?string $id = null): void
    {
        $this->client->post('window/resize', [
            'id' => $this->resolveId($id),
            'width' => $width,
            'height' => $height,
        ]);
    }

    public function position(int $x, int $y, bool $animate = false, ?string $id = null): void
    {
        $this->client->post('window/position', [
            'id' => $this->resolveId($id),
            'x' => $x,
            'y' => $y,
            'animate' => $animate,
        ]);
    }

    /**
     * The runtime preventDefault()s Electron's page-title-updated, so the
     * document <title> never reaches the native title bar. This is the only way
     * to change it.
     */
    public function title(string $title, ?string $id = null): void
    {
        $this->client->post('window/title', [
            'id' => $this->resolveId($id),
            'title' => $title,
        ]);
    }

    public function navigate(string $url, ?string $id = null): void
    {
        $this->client->post('window/url', [
            'id' => $this->resolveId($id),
            'url' => $this->urls->absolute($url),
        ]);
    }

    public function closable(bool $closable = true, ?string $id = null): void
    {
        $this->client->post('window/closable', [
            'id' => $this->resolveId($id),
            'closable' => $closable,
        ]);
    }

    public function alwaysOnTop(bool $alwaysOnTop = true, ?string $id = null): void
    {
        $this->client->post('window/always-on-top', [
            'id' => $this->resolveId($id),
            'alwaysOnTop' => $alwaysOnTop,
        ]);
    }

    /** macOS only; a no-op elsewhere. */
    public function windowButtonVisibility(bool $visible = true, ?string $id = null): void
    {
        $this->client->post('window/window-button-visibility', [
            'id' => $this->resolveId($id),
            'windowButtonVisibility' => $visible,
        ]);
    }

    public function zoomFactor(float $factor, ?string $id = null): void
    {
        $this->client->post('window/set-zoom-factor', [
            'id' => $this->resolveId($id),
            'zoomFactor' => $factor,
        ]);
    }

    public function showDevTools(?string $id = null): void
    {
        $this->client->post('window/show-dev-tools', ['id' => $this->resolveId($id)]);
    }

    public function hideDevTools(?string $id = null): void
    {
        $this->client->post('window/hide-dev-tools', ['id' => $this->resolveId($id)]);
    }

    public function get(string $id = 'main'): ?Window
    {
        // Encoded, because this id reaches the URL rather than a payload — and unlike most
        // of them it can arrive from outside the application: detectId() reads `_windowId`
        // out of the Referer or the current URI, so `../app/quit` would have addressed a
        // different endpoint instead of asking about a window that does not exist. Encoding
        // rather than validating keeps every id an application might legitimately choose
        // working, while confining it to one path segment.
        $response = $this->client->get('window/get/'.rawurlencode($id));

        // Any unusable answer means null, not just a 404. getWindowData() throws a
        // bare string for some unknown ids, which arrives as a 500 with an HTML
        // body; decoding that gave [], and Window::fromRuntime([]) is a confident
        // window with an empty id, zero size and every flag false. A caller reading
        // ->closable off that gets a wrong answer rather than an absent one.
        if (!$response->successful() || null === $response->data) {
            return null;
        }

        return Window::fromRuntime((array) $response->array());
    }

    /**
     * Fallible by design: the runtime calls getFocusedWindow().id with no null
     * guard, so when the app is backgrounded this endpoint throws on its side
     * and answers 500. Returns null rather than propagating that.
     */
    public function current(): ?Window
    {
        $response = $this->client->get('window/current');

        if (!$response->successful() || null === $response->data) {
            return null;
        }

        return Window::fromRuntime((array) $response->data);
    }

    /** @return list<Window> */
    public function all(): array
    {
        $windows = $this->client->get('window/all')->array();

        return array_values(array_map(
            static fn (array $window): Window => Window::fromRuntime($window),
            array_filter($windows, 'is_array'),
        ));
    }

    /**
     * The runtime appends ?_windowId=<id> to every navigation it performs. The
     * current request's window is recovered from the Referer first, then the
     * current URL — keep that order: a form POST carries the window in its
     * Referer, not its own URL.
     */
    public function detectId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        foreach ([$request->headers->get('Referer'), $request->getUri()] as $candidate) {
            if (null === $candidate || '' === $candidate) {
                continue;
            }

            parse_str((string) parse_url($candidate, \PHP_URL_QUERY), $query);

            if (isset($query['_windowId']) && \is_string($query['_windowId']) && '' !== $query['_windowId']) {
                return $query['_windowId'];
            }
        }

        return null;
    }

    private function resolveId(?string $id): string
    {
        return $id ?? $this->detectId() ?? 'main';
    }
}
