<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/**
 * A text run.
 *
 * The prop is `text`, and it is the only key emitted unless typography is set —
 * key order in props is part of the content hash, so they are added in a fixed
 * order rather than however the caller happened to set them.
 */
final class Text extends Element
{
    protected string $type = 'text';

    /** @var array<string, mixed> */
    private array $textProps = [];

    public static function make(string $text = ''): self
    {
        $el = new self();

        if ('' !== $text) {
            $el->textProps['text'] = $text;
        }

        return $el;
    }

    public function fontSize(int|float $size): self
    {
        $this->textProps['fontSize'] = $size;

        return $this;
    }

    public function fontWeight(string|int $weight): self
    {
        $this->textProps['fontWeight'] = $weight;

        return $this;
    }

    public function color(string $color): self
    {
        $this->textProps['color'] = $color;

        return $this;
    }

    public function textAlign(string $align): self
    {
        $this->textProps['textAlign'] = $align;

        return $this;
    }

    public function maxLines(int $lines): self
    {
        $this->textProps['maxLines'] = $lines;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->textProps;
    }
}
