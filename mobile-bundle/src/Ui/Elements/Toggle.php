<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

use Native\Symfony\Mobile\Ui\CallbackRegistry;

/** An on/off switch. */
final class Toggle extends Element
{
    protected string $type = 'toggle';

    /** @var array<string, mixed> */
    private array $toggleProps = [];

    private ?string $changeMethod = null;

    public static function make(bool $value = false): self
    {
        $el = new self();
        $el->toggleProps['value'] = $value;

        return $el;
    }

    public function label(string $label): self
    {
        $this->toggleProps['label'] = $label;

        return $this;
    }

    public function onChange(string $expression): self
    {
        $this->changeMethod = $expression;

        return $this;
    }

    protected function resolvedProps(CallbackRegistry $registry): array
    {
        $props = $this->toggleProps;

        if (null !== $this->changeMethod) {
            $props['on_change'] = $registry->register($this->changeMethod);
        }

        return $props;
    }
}
