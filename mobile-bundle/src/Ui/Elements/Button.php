<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/**
 * A button. Its slot text becomes the `label` prop rather than a child node, which
 * is what the renderers expect.
 */
final class Button extends Element
{
    protected string $type = 'button';

    /** @var array<string, mixed> */
    private array $buttonProps = [];

    public static function make(string $label = ''): self
    {
        $el = new self();

        if ('' !== $label) {
            $el->buttonProps['label'] = $label;
        }

        return $el;
    }

    public function variant(string $variant): self
    {
        $this->buttonProps['variant'] = $variant;

        return $this;
    }

    public function disabled(bool $disabled = true): self
    {
        $this->buttonProps['disabled'] = $disabled;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->buttonProps;
    }
}
