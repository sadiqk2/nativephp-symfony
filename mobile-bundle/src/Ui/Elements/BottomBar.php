<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A bar pinned to the bottom of the screen. */
final class BottomBar extends Element
{
    protected string $type = 'bottom_bar';

    /** @var array<string, mixed> */
    private array $elementProps = [];

    public static function make(): self
    {
        return new self();
    }

    public function label(string $label): self
    {
        $this->elementProps['label'] = $label;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->elementProps;
    }
}
