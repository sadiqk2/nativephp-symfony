<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/**
 * A floating action button.
 *
 * Emits `pressable`, not `fab` — upstream models it as a positioned pressable
 * rather than a distinct native type, so the renderers need no FAB case.
 */
final class Fab extends Element
{
    protected string $type = 'pressable';

    public static function make(Element ...$children): self
    {
        $el = new self();
        $el->children = array_values($children);

        return $el;
    }
}
