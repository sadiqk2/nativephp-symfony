<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** Flexible empty space — the idiomatic way to push siblings apart. */
final class Spacer extends Element
{
    protected string $type = 'spacer';

    public static function make(Element ...$children): self
    {
        $el = new self();
        $el->children = array_values($children);

        return $el;
    }

    /**
     * A spacer's whole purpose is to claim the remaining space in a flex container,
     * so it grows by default — otherwise every author has to remember to say so.
     *
     * Note the wire key is snake_case: layout keys are snake_case on the wire even
     * though element props are camelCase. That inconsistency is upstream's, and the
     * byte-comparison test is what caught it.
     */
    protected function layoutDefaults(): array
    {
        return ['flex_grow' => 1];
    }
}
