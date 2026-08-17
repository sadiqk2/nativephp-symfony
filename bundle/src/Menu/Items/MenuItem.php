<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Menu\Items;

/**
 * One entry in a menu template.
 *
 * The wire shape is defined by the runtime's helper/index.ts::compileMenu, which
 * switches on `type` and attaches a click handler. Anything compileMenu does not
 * recognise falls through to a default item with a click handler, so plain
 * Electron MenuItemConstructorOptions keys pass straight through.
 */
interface MenuItem
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
