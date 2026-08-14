<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A grid that builds only its visible cells. */
final class LazyGrid extends Element
{
    protected string $type = 'lazy_grid';

    public static function make(Element ...$children): self
    {
        $el = new self();
        $el->children = array_values($children);

        return $el;
    }
}
