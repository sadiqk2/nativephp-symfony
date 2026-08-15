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

    /** @var array<string, mixed> */
    private array $scrollProps = [];

    /**
     * Sets `axis` alongside `horizontal`, as upstream does: the renderers read the
     * axis string, and the boolean alone left it scrolling vertically.
     */
    public function horizontal(bool $horizontal = true): self
    {
        $this->scrollProps['horizontal'] = $horizontal;
        $this->scrollProps['axis'] = $horizontal ? 'horizontal' : 'vertical';

        if ($horizontal) {
            // The main axis has to move with the scroll axis, or overflow:scroll
            // applies to the height while the content runs along the width — which
            // is a carousel that does not scroll. Not set by both(): 2D mode
            // bypasses flex and the renderers honour each child's declared frame.
            $this->layout['flex_direction'] = 1;
        }

        return $this;
    }

    public function both(): self
    {
        $this->scrollProps['axis'] = 'both';

        return $this;
    }

    public function showsIndicators(bool $shows = true): self
    {
        $this->scrollProps['shows_indicators'] = $shows;

        return $this;
    }

    public function autoScrollTo(int $index): self
    {
        $this->scrollProps['auto_scroll_to'] = $index;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->scrollProps;
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
