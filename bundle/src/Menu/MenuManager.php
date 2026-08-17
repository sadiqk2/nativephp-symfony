<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Window\UrlResolver;

/**
 * The application menu (POST /api/menu) and the page context menu
 * (POST/DELETE /api/context).
 */
final class MenuManager
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly ?UrlResolver $urls = null,
    )
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
        $this->client->post('menu', ['items' => $this->resolveUrls($menu->toArray())]);
    }

    /**
     * Prepend items to the right-click menu in every window.
     *
     * Answers 200 *before* doing the work, so a failure here is invisible to the
     * caller. Replaces any previously registered context menu.
     */
    public function context(Menu $menu): void
    {
        $this->client->post('context', ['entries' => $this->resolveUrls($menu->toArray())]);
    }

    public function removeContext(): void
    {
        $this->client->delete('context');
    }

    /**
     * Absolutise every link URL in the tree, recursively.
     *
     * A link item's URL ends up in the runtime's `goToUrl`, which calls
     * `loadURL()` — and that rejects a relative path with ERR_INVALID_URL, so the
     * menu item silently does nothing while its event still fires. WindowManager
     * and PendingMenuBar both resolve their URLs already; this was the one path
     * that did not, which made a relative URL work or not depending on which API
     * you reached for.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function resolveUrls(array $items): array
    {
        if (null === $this->urls) {
            return $items;
        }

        foreach ($items as $index => $item) {
            if (isset($item['url']) && \is_string($item['url'])) {
                $items[$index]['url'] = $this->urls->absolute($item['url']);
            }

            if (isset($item['submenu']) && \is_array($item['submenu'])) {
                /** @var list<array<string, mixed>> $submenu */
                $submenu = $item['submenu'];
                $items[$index]['submenu'] = $this->resolveUrls($submenu);
            }
        }

        return $items;
    }
}
