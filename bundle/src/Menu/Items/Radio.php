<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu\Items;

/**
 * A radio item. Electron groups consecutive radio items automatically — separate
 * groups need a separator or another item type between them.
 *
 * Same state caveat as Checkbox: the runtime owns `checked` after the first click.
 */
final class Radio implements MenuItem
{
    public function __construct(
        private readonly string $label,
        private readonly bool $checked = false,
        private readonly ?string $id = null,
        private readonly ?string $event = null,
        private readonly ?string $accelerator = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => 'radio',
            'label' => $this->label,
            'checked' => $this->checked,
            'id' => $this->id,
            'event' => $this->event,
            'accelerator' => $this->accelerator,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
