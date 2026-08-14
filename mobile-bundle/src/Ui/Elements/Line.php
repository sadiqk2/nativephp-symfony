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

    public static function make(): self
    {
        return new self();
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->elementProps;
    }
}
