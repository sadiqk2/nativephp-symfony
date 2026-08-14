<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/**
 * A platform icon.
 *
 * Upstream accepts SF Symbol and Material symbol enums for per-platform names; this
 * takes strings, since those enums are upstream types a Symfony app has no reason to
 * depend on. The wire props are identical.
 */
final class Icon extends Element
{
    protected string $type = 'icon';

    /** @var array<string, mixed> */
    private array $iconProps = [];

    public static function make(string $name = ''): self
    {
        $el = new self();

        if ('' !== $name) {
            $el->iconProps['name'] = $name;
        }

        return $el;
    }

    /** An SF Symbol name, preferred over `name` on iOS. */
    public function ios(string $symbol): self
    {
        $this->iconProps['ios'] = $symbol;

        return $this;
    }

    /** A Material symbol name, preferred over `name` on Android. */
    public function android(string $symbol): self
    {
        $this->iconProps['android'] = $symbol;

        return $this;
    }

    public function size(int|float $size): self
    {
        $this->iconProps['size'] = $size;

        return $this;
    }

    public function color(string $color): self
    {
        $this->iconProps['color'] = $color;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->iconProps;
    }
}
