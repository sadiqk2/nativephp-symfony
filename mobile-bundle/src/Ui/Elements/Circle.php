<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A circle — implemented as a fully-rounded rect, hence the style default. */
final class Circle extends Element
{
    protected string $type = 'circle';

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

    protected function styleDefaults(): array
    {
        return ['border_radius' => 9999];
    }
}
