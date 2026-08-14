<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

use Native\Symfony\Mobile\Ui\CallbackRegistry;

/**
 * A text field.
 *
 * `on_change` is registered like a press handler: the expression crosses as an id
 * and the native side sends it back with the new value.
 */
final class TextInput extends Element
{
    protected string $type = 'text_input';

    /** @var array<string, mixed> */
    private array $inputProps = [];

    private ?string $changeMethod = null;

    /**
     * @param string|null $value Omitted entirely when null, rather than sent as an
     *                           empty string. Upstream's make() takes no argument, so
     *                           an unconditional value prop makes an otherwise
     *                           identical node diverge — and it feeds the content hash,
     *                           so the divergence propagates to every ancestor.
     */
    public static function make(?string $value = null): self
    {
        $el = new self();

        if (null !== $value) {
            $el->inputProps['value'] = $value;
        }

        return $el;
    }

    public function value(string $value): self
    {
        $this->inputProps['value'] = $value;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->inputProps['placeholder'] = $placeholder;

        return $this;
    }

    public function secure(bool $secure = true): self
    {
        $this->inputProps['secure'] = $secure;

        return $this;
    }

    public function onChange(string $expression): self
    {
        $this->changeMethod = $expression;

        return $this;
    }

    protected function resolvedProps(CallbackRegistry $registry): array
    {
        $props = $this->inputProps;

        if (null !== $this->changeMethod) {
            $props['on_change'] = $registry->register($this->changeMethod);
        }

        return $props;
    }
}
