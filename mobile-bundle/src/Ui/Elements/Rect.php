<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A rectangle, for use inside a canvas. */
final class Rect extends Element
{
    protected string $type = 'rect';

    /** @var array<string, mixed> */
    private array $elementProps = [];

    /** Position within the parent canvas. Floats, matching upstream's cast. */
    public function at(float $left, float $top): self
    {
        $this->elementProps['left'] = $left;
        $this->elementProps['top'] = $top;

        return $this;
    }

    public static function make(): self
    {
        return new self();
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->elementProps;
    }
}
