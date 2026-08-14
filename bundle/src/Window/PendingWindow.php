<?php

declare(strict_types=1);

namespace Native\Symfony\Window;

use Native\Symfony\Contract\ClientInterface;

/**
 * Fluent builder for window/open — the runtime's largest request, 39 keys.
 *
 * Only id/url/width/height are required; everything else is omitted from the
 * payload unless set, because the runtime distinguishes absent from null in a
 * few places (notably showDevTools, where `false` suppresses the devtools that
 * dev mode would otherwise open, but absent does not).
 */
final class PendingWindow
{
    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlResolver $urls,
        string $id,
    ) {
        $this->payload = ['id' => $id];
    }

    public function url(string $url): self
    {
        $this->payload['url'] = $url;

        return $this;
    }

    public function title(string $title): self
    {
        $this->payload['title'] = $title;

        return $this;
    }

    public function width(int $width): self
    {
        $this->payload['width'] = $width;

        return $this;
    }

    public function height(int $height): self
    {
        $this->payload['height'] = $height;

        return $this;
    }

    public function size(int $width, int $height): self
    {
        return $this->width($width)->height($height);
    }

    public function minSize(int $width, int $height): self
    {
        $this->payload['minWidth'] = $width;
        $this->payload['minHeight'] = $height;

        return $this;
    }

    public function maxSize(int $width, int $height): self
    {
        $this->payload['maxWidth'] = $width;
        $this->payload['maxHeight'] = $height;

        return $this;
    }

    public function position(int $x, int $y): self
    {
        $this->payload['x'] = $x;
        $this->payload['y'] = $y;

        return $this;
    }

    public function frameless(): self
    {
        $this->payload['frame'] = false;

        return $this;
    }

    public function transparent(bool $transparent = true): self
    {
        $this->payload['transparency'] = $transparent;

        return $this;
    }

    public function backgroundColor(string $color): self
    {
        $this->payload['backgroundColor'] = $color;

        return $this;
    }

    public function vibrancy(string $vibrancy): self
    {
        $this->payload['vibrancy'] = $vibrancy;

        return $this;
    }

    public function titleBarStyle(string $style): self
    {
        $this->payload['titleBarStyle'] = $style;

        return $this;
    }

    public function trafficLightPosition(int $x, int $y): self
    {
        $this->payload['trafficLightPosition'] = ['x' => $x, 'y' => $y];

        return $this;
    }

    public function windowButtonVisibility(bool $visible = true): self
    {
        $this->payload['windowButtonVisibility'] = $visible;

        return $this;
    }

    public function resizable(bool $resizable = true): self
    {
        $this->payload['resizable'] = $resizable;

        return $this;
    }

    public function movable(bool $movable = true): self
    {
        $this->payload['movable'] = $movable;

        return $this;
    }

    public function minimizable(bool $minimizable = true): self
    {
        $this->payload['minimizable'] = $minimizable;

        return $this;
    }

    public function maximizable(bool $maximizable = true): self
    {
        $this->payload['maximizable'] = $maximizable;

        return $this;
    }

    public function closable(bool $closable = true): self
    {
        $this->payload['closable'] = $closable;

        return $this;
    }

    public function focusable(bool $focusable = true): self
    {
        $this->payload['focusable'] = $focusable;

        return $this;
    }

    public function alwaysOnTop(bool $alwaysOnTop = true): self
    {
        $this->payload['alwaysOnTop'] = $alwaysOnTop;

        return $this;
    }

    public function hasShadow(bool $hasShadow = true): self
    {
        $this->payload['hasShadow'] = $hasShadow;

        return $this;
    }

    public function skipTaskbar(bool $skip = true): self
    {
        $this->payload['skipTaskbar'] = $skip;

        return $this;
    }

    public function hiddenInMissionControl(bool $hidden = true): self
    {
        $this->payload['hiddenInMissionControl'] = $hidden;

        return $this;
    }

    public function autoHideMenuBar(bool $autoHide = true): self
    {
        $this->payload['autoHideMenuBar'] = $autoHide;

        return $this;
    }

    public function fullscreen(bool $fullscreen = true): self
    {
        $this->payload['fullscreen'] = $fullscreen;

        return $this;
    }

    public function fullscreenable(bool $fullscreenable = true): self
    {
        $this->payload['fullscreenable'] = $fullscreenable;

        return $this;
    }

    public function kiosk(bool $kiosk = true): self
    {
        $this->payload['kiosk'] = $kiosk;

        return $this;
    }

    public function zoomFactor(float $factor): self
    {
        $this->payload['zoomFactor'] = $factor;

        return $this;
    }

    public function showDevTools(bool $show = true): self
    {
        $this->payload['showDevTools'] = $show;

        return $this;
    }

    /**
     * Persist geometry to window-state-{id}.json between launches. The stored
     * size only wins over the requested one when the window is resizable —
     * that asymmetry is the runtime's, not ours.
     */
    public function rememberState(bool $remember = true): self
    {
        $this->payload['rememberState'] = $remember;

        return $this;
    }

    /** @param array<string, mixed> $preferences */
    public function webPreferences(array $preferences): self
    {
        // sandbox, preload and contextIsolation are force-applied by the runtime
        // after this merge and cannot be overridden here.
        $this->payload['webPreferences'] = $preferences;

        return $this;
    }

    public function preventLeavingDomain(bool $prevent = true): self
    {
        $this->payload['preventLeaveDomain'] = $prevent;

        return $this;
    }

    public function preventLeavingPage(bool $prevent = true): self
    {
        $this->payload['preventLeavePage'] = $prevent;

        return $this;
    }

    public function suppressNewWindows(bool $suppress = true): self
    {
        $this->payload['suppressNewWindows'] = $suppress;

        return $this;
    }

    /**
     * Idempotent: if this id already exists the runtime shows and focuses it and
     * creates nothing, so calling open() from the booted handler is safe even
     * though /booted can fire more than once.
     */
    public function open(): void
    {
        $payload = $this->payload;
        $payload['url'] = $this->urls->absolute((string) ($payload['url'] ?? '/'));
        $payload['width'] ??= 1000;
        $payload['height'] ??= 700;

        // Must be sent. The runtime does setZoomFactor(parseFloat(zoomFactor))
        // on dom-ready with no guard, so an absent value becomes NaN and the
        // page renders at an absurd zoom. Laravel's Window defaults the property
        // to 1.0 and always serialises it, which hides the bug upstream.
        $payload['zoomFactor'] ??= 1.0;

        $this->client->post('window/open', $payload);
    }

    /** @return array<string, mixed> Exposed for assertions in tests. */
    public function payload(): array
    {
        return $this->payload;
    }
}
