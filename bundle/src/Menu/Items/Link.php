<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu\Items;

/**
 * An item that navigates somewhere on click.
 *
 * compileMenu rewrites `type: link` to `normal` and attaches a handler that fires
 * the event and then either opens the URL in the user's browser
 * (`openInBrowser: true`) or navigates the *focused* window to it — with
 * `?_windowId=` appended, so the target page can tell which window it is in.
 *
 * If no window has focus the navigation is silently skipped; the event still fires.
 */
final class Link implements MenuItem
{
    public function __construct(
        private readonly string $label,
        private readonly string $url,
        private readonly bool $openInBrowser = false,
        private readonly ?string $id = null,
        private readonly ?string $event = null,
        private readonly ?string $accelerator = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => 'link',
            'label' => $this->label,
            'url' => $this->url,
            'openInBrowser' => $this->openInBrowser ?: null,
            'id' => $this->id,
            'event' => $this->event,
            'accelerator' => $this->accelerator,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
