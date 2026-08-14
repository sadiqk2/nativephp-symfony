<?php

declare(strict_types=1);

namespace Native\Symfony\Event\Menu;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A menu item was activated — application menu, dock menu, tray context menu or
 * page context menu; the runtime does not distinguish them.
 *
 * Runtime payload: {item: {id, label, checked}, combo} — named.
 *
 * Dispatched under this class only when the item carried no `event` of its own.
 * An item with an explicit event name arrives as a NativeEvent instead, which is
 * how you route clicks without inspecting the payload.
 *
 * For checkbox and radio items, `checked` is the state the runtime has already
 * flipped to on its side. It is not queryable afterwards — this event is the only
 * notification, so persist it if you need it.
 */
final class MenuItemClicked extends Event
{
    /**
     * @param array{id?: string|null, label?: string|null, checked?: bool|null} $item
     * @param array<string, mixed>                                             $combo
     */
    public function __construct(
        public readonly array $item,
        public readonly array $combo = [],
    ) {
    }

    public function id(): ?string
    {
        return isset($this->item['id']) ? (string) $this->item['id'] : null;
    }

    public function label(): ?string
    {
        return isset($this->item['label']) ? (string) $this->item['label'] : null;
    }

    public function checked(): ?bool
    {
        return isset($this->item['checked']) ? (bool) $this->item['checked'] : null;
    }
}
