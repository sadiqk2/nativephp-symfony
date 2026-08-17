<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu;

use Native\Symfony\Desktop\Enums\MenuRole;
use Native\Symfony\Desktop\Menu\Items\Checkbox;
use Native\Symfony\Desktop\Menu\Items\Label;
use Native\Symfony\Desktop\Menu\Items\Link;
use Native\Symfony\Desktop\Menu\Items\MenuItem;
use Native\Symfony\Desktop\Menu\Items\Radio;
use Native\Symfony\Desktop\Menu\Items\Role;
use Native\Symfony\Desktop\Menu\Items\Separator;
use Native\Symfony\Desktop\Menu\Items\Submenu;

/**
 * Builds a menu template. Immutable — every method returns a new instance, so a
 * partially-built menu can be shared and specialised without surprises.
 */
final class Menu
{
    /** @param list<MenuItem> $items */
    private function __construct(private readonly array $items = [])
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function make(MenuItem ...$items): self
    {
        return new self(array_values($items));
    }

    public function add(MenuItem ...$items): self
    {
        return new self([...$this->items, ...array_values($items)]);
    }

    public function label(
        string $label,
        ?string $id = null,
        ?string $event = null,
        ?string $accelerator = null,
        bool $enabled = true,
        bool $visible = true,
    ): self {
        return $this->add(new Label($label, $id, $event, $accelerator, $enabled, $visible));
    }

    public function link(
        string $label,
        string $url,
        bool $openInBrowser = false,
        ?string $id = null,
        ?string $event = null,
        ?string $accelerator = null,
    ): self {
        return $this->add(new Link($label, $url, $openInBrowser, $id, $event, $accelerator));
    }

    public function checkbox(
        string $label,
        bool $checked = false,
        ?string $id = null,
        ?string $event = null,
        ?string $accelerator = null,
    ): self {
        return $this->add(new Checkbox($label, $checked, $id, $event, $accelerator));
    }

    public function radio(
        string $label,
        bool $checked = false,
        ?string $id = null,
        ?string $event = null,
        ?string $accelerator = null,
    ): self {
        return $this->add(new Radio($label, $checked, $id, $event, $accelerator));
    }

    public function role(MenuRole|string $role, ?string $label = null): self
    {
        return $this->add(new Role($role, $label));
    }

    public function separator(): self
    {
        return $this->add(new Separator());
    }

    public function submenu(string $label, self|MenuItem ...$items): self
    {
        $resolved = [];

        foreach ($items as $item) {
            if ($item instanceof self) {
                $resolved = [...$resolved, ...$item->items];

                continue;
            }

            $resolved[] = $item;
        }

        return $this->add(new Submenu($label, ...$resolved));
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (MenuItem $i): array => $i->toArray(), $this->items);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
