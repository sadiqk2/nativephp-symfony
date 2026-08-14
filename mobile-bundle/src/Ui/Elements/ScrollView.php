<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A scrollable container. */
final class ScrollView extends Element
{
    protected string $type = 'scroll_view';

    public static function make(Element ...$children): self
    {
        $el = new self();
        $el->children = array_values($children);

        return $el;
    }

    /**
     * overflow=2 (scroll). Without it a scroll_view lays out like a plain column and
     * silently does not scroll — found by inventorying upstream's defaults rather than
     * by reading them, which is why the exhaustive comparison test below exists.
     */
    protected function layoutDefaults(): array
    {
        return ['overflow' => 2];
    }
}
