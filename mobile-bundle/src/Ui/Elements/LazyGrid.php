<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A grid that builds only its visible cells. */
final class LazyGrid extends Element
{
    protected string $type = 'lazy_grid';

    /** @var array<string, mixed> */
    private array $gridProps = [];

    /** Clamped at 1, as upstream does — a zero-column grid lays out nothing. */
    public function columns(int $count): self
    {
        $this->gridProps['columns'] = max(1, $count);

        return $this;
    }

    public function gap(float $gap): self
    {
        $this->gridProps['gap'] = $gap;

        return $this;
    }

    public function horizontal(bool $horizontal = true): self
    {
        $this->gridProps['horizontal'] = $horizontal;

        return $this;
    }

    public function showsIndicators(bool $shows = true): self
    {
        $this->gridProps['shows_indicators'] = $shows;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->gridProps;
    }

    public static function make(Element ...$children): self
    {
        $el = new self();
        $el->children = array_values($children);

        return $el;
    }
}
