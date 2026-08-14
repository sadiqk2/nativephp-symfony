<?php

declare(strict_types=1);

namespace Native\Symfony\MenuBar;

use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Menu\Menu;
use Native\Symfony\Window\UrlResolver;

/**
 * Builder for menu-bar/create — a tray icon, with or without a popover window.
 *
 * Two distinct modes:
 *   - onlyShowContextMenu(): a bare tray icon with a menu, no window.
 *   - anything else: a tray icon with a popover window showing `url`.
 *
 * Creating a second time destroys the existing tray and **suppresses the
 * MenuBarCreated event**, so that event fires only on first creation.
 */
final class PendingMenuBar
{
    /** @var array<string, mixed> */
    private array $payload = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlResolver $urls,
    ) {
    }

    public function url(string $url): self
    {
        $this->payload['url'] = $url;

        return $this;
    }

    /** Text shown next to the tray icon (macOS). */
    public function label(string $label): self
    {
        $this->payload['label'] = $label;

        return $this;
    }

    public function tooltip(string $tooltip): self
    {
        $this->payload['tooltip'] = $tooltip;

        return $this;
    }

    /**
     * Path to the tray image. Defaults to the app icon with `icon.png` swapped for
     * `IconTemplate.png` — on macOS a "Template" suffix is what makes the image
     * adapt to light and dark menu bars.
     */
    public function icon(string $path): self
    {
        $this->payload['icon'] = $path;

        return $this;
    }

    public function size(int $width, int $height): self
    {
        $this->payload['width'] = $width;
        $this->payload['height'] = $height;

        return $this;
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

    public function resizable(bool $resizable = true): self
    {
        $this->payload['resizable'] = $resizable;

        return $this;
    }

    public function alwaysOnTop(bool $alwaysOnTop = true): self
    {
        $this->payload['alwaysOnTop'] = $alwaysOnTop;

        return $this;
    }

    public function vibrancy(string $vibrancy): self
    {
        $this->payload['vibrancy'] = $vibrancy;

        return $this;
    }

    public function backgroundColor(string $color): self
    {
        $this->payload['backgroundColor'] = $color;

        return $this;
    }

    public function transparent(bool $transparent = true): self
    {
        $this->payload['transparency'] = $transparent;

        return $this;
    }

    public function showDockIcon(bool $show = true): self
    {
        $this->payload['showDockIcon'] = $show;

        return $this;
    }

    public function showOnAllWorkspaces(bool $show = true): self
    {
        $this->payload['showOnAllWorkspaces'] = $show;

        return $this;
    }

    /** trayCenter, trayBottomCenter, topRight, bottomLeft, … */
    public function windowPosition(string $position): self
    {
        $this->payload['windowPosition'] = $position;

        return $this;
    }

    public function contextMenu(Menu $menu): self
    {
        $this->payload['contextMenu'] = $menu->toArray();

        return $this;
    }

    /** A tray icon with only a menu — no popover window. */
    public function onlyShowContextMenu(bool $only = true): self
    {
        $this->payload['onlyShowContextMenu'] = $only;

        return $this;
    }

    /** @param array<string, mixed> $preferences */
    public function webPreferences(array $preferences): self
    {
        $this->payload['webPreferences'] = $preferences;

        return $this;
    }

    /**
     * Create it. Answers 200 before doing any work, so a failure — a missing icon,
     * for instance — is not reported here. Watch for MenuBarCreated instead.
     */
    public function create(): void
    {
        $payload = $this->payload;

        if (isset($payload['url'])) {
            $payload['url'] = $this->urls->absolute((string) $payload['url']);
        }

        $this->client->post('menu-bar/create', $payload);
    }

    /** @return array<string, mixed> Exposed for assertions in tests. */
    public function payload(): array
    {
        return $this->payload;
    }
}
