<?php

declare(strict_types=1);

namespace Native\Symfony\Menu;

use Native\Symfony\Contract\ClientInterface;

/**
 * The application menu (POST /api/menu) and the page context menu
 * (POST/DELETE /api/context).
 */
final class MenuManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * Replace the application menu. The runtime clears the existing one first, so
     * this is a full replacement — there is no incremental API.
     *
     * On macOS the first submenu is the application menu regardless of its label,
     * so start with a MenuRole::AppMenu or a submenu named after your app.
     */
    public function set(Menu $menu): void
    {
        $this->client->post('menu', ['items' => $menu->toArray()]);
    }

    /**
     * Prepend items to the right-click menu in every window.
     *
     * Answers 200 *before* doing the work, so a failure here is invisible to the
     * caller. Replaces any previously registered context menu.
     */
    public function context(Menu $menu): void
    {
        $this->client->post('context', ['entries' => $menu->toArray()]);
    }

    public function removeContext(): void
    {
        $this->client->delete('context');
    }
}
