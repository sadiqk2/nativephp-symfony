<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A spinner. */
final class ActivityIndicator extends Element
{
    protected string $type = 'activity_indicator';

    /** @var array<string, mixed> */
    private array $props = [];

    public static function make(): self
    {
        return new self();
    }

    public function color(string $color): self
    {
        $this->props['color'] = $color;

        return $this;
    }

    public function size(string $size): self
    {
        $this->props['size'] = $size;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
