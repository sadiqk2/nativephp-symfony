<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A drawing surface for rect, circle and line children. */
final class Canvas extends Element
{
    protected string $type = 'canvas';

    public static function make(Element ...$children): self
    {
        $el = new self();
        $el->children = array_values($children);

        return $el;
    }
}
