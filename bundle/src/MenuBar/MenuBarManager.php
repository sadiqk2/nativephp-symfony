<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\MenuBar;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Menu\Menu;
use Native\Symfony\Desktop\Window\UrlResolver;

/**
 * Tray / menu bar — 9 endpoints.
 *
 * **Every one answers 200 before acting.** A 200 here means the request was
 * received, not that it worked; errors are unobservable by the caller. And show(),
 * hide() and resize() dereference the active menu bar without a null check on the
 * runtime side, so calling them before create() throws there.
 */
final class MenuBarManager
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlResolver $urls,
    ) {
    }

    public function create(): PendingMenuBar
    {
        return new PendingMenuBar($this->client, $this->urls);
    }

    public function show(): void
    {
        $this->client->post('menu-bar/show');
    }

    public function hide(): void
    {
        $this->client->post('menu-bar/hide');
    }

    public function resize(int $width, int $height): void
    {
        $this->client->post('menu-bar/resize', ['width' => $width, 'height' => $height]);
    }

    public function label(string $label): void
    {
        $this->client->post('menu-bar/label', ['label' => $label]);
    }

    public function tooltip(string $tooltip): void
    {
        $this->client->post('menu-bar/tooltip', ['tooltip' => $tooltip]);
    }

    public function icon(string $path): void
    {
        $this->client->post('menu-bar/icon', ['icon' => $path]);
    }

    public function contextMenu(Menu $menu): void
    {
        $this->client->post('menu-bar/context-menu', ['contextMenu' => $menu->toArray()]);
    }

    public function showContextMenu(): void
    {
        $this->client->post('menu-bar/show-context-menu');
    }
}
