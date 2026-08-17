<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu\Items;

use Native\Symfony\Desktop\Enums\MenuRole;

/**
 * A built-in OS behaviour (copy, paste, quit, the whole edit menu, …).
 *
 * compileMenu reduces a role item to `{role, label?}` and discards every other
 * key — including `id` and `event` — so a role item can never report a click back
 * to PHP. The OS owns the behaviour; that is the point of using one.
 */
final class Role implements MenuItem
{
    public function __construct(
        private readonly MenuRole|string $role,
        private readonly ?string $label = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => 'role',
            'role' => $this->role instanceof MenuRole ? $this->role->value : $this->role,
            'label' => $this->label,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
