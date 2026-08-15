<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A straight line, for use inside a canvas. */
final class Line extends Element
{
    protected string $type = 'line';

    /** @var array<string, mixed> */
    private array $elementProps = [];

    /**
     * Floats, and all four required: a line with no coordinates draws nothing, which
     * is what every <line> in this bundle did before these existed.
     */
    public function from(float $x, float $y): self
    {
        $this->elementProps['from_x'] = $x;
        $this->elementProps['from_y'] = $y;

        return $this;
    }

    public function to(float $x, float $y): self
    {
        $this->elementProps['to_x'] = $x;
        $this->elementProps['to_y'] = $y;

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
