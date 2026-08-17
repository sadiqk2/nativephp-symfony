<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu\Items;

/**
 * A nested menu. compileMenu recurses into `submenu`, accepting either a plain
 * list or `{submenu: [...]}`; this always emits the list form.
 */
final class Submenu implements MenuItem
{
    /** @var list<MenuItem> */
    private readonly array $items;

    public function __construct(
        private readonly string $label,
        MenuItem ...$items,
    ) {
        $this->items = array_values($items);
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'submenu' => array_map(static fn (MenuItem $i): array => $i->toArray(), $this->items),
        ];
    }
}
