<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu\Items;

/**
 * A toggleable item.
 *
 * The runtime flips `checked` in its own copy of the template on click and reports
 * the new value in the event payload. That state is never queryable afterwards, so
 * the event is your only chance to persist it — and a menu rebuilt from stale
 * state will silently disagree with what the user sees.
 */
final class Checkbox implements MenuItem
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
            'type' => 'checkbox',
            'label' => $this->label,
            'checked' => $this->checked,
            'id' => $this->id,
            'event' => $this->event,
            'accelerator' => $this->accelerator,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
