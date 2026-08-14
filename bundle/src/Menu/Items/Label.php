<?php

declare(strict_types=1);

namespace Native\Symfony\Menu\Items;

/**
 * A plain clickable item.
 *
 * With no `event`, a click arrives as Event\Menu\MenuItemClicked and you route on
 * the id. With one, it arrives as a NativeEvent under that name — usually easier,
 * because a listener can subscribe to the specific action instead of filtering.
 */
final class Label implements MenuItem
{
    public function __construct(
        private readonly string $label,
        private readonly ?string $id = null,
        private readonly ?string $event = null,
        private readonly ?string $accelerator = null,
        private readonly bool $enabled = true,
        private readonly bool $visible = true,
    ) {
    }

    public function toArray(): array
    {
        $item = array_filter([
            'label' => $this->label,
            'id' => $this->id,
            'event' => $this->event,
            'accelerator' => $this->accelerator,
        ], static fn (mixed $v): bool => null !== $v);

        // Only send these when they differ from Electron's defaults, so the
        // template stays readable in the runtime's logs.
        if (!$this->enabled) {
            $item['enabled'] = false;
        }

        if (!$this->visible) {
            $item['visible'] = false;
        }

        return $item;
    }
}
