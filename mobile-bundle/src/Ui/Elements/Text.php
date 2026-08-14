<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/**
 * A text run.
 *
 * The prop is `text`, and it is the only key emitted unless typography is set — key order
 * in props is part of the content hash, so they are added in a fixed order rather than
 * however the caller happened to set them.
 *
 * Wire prop names are **snake_case** (`font_size`), while the style parser's intermediate
 * vocabulary is camelCase (`fontSize`). The setters take the camelCase name a reader
 * expects and write the snake_case one the renderers read. An unrecognised key is silently
 * ignored on the device, so this is not a cosmetic distinction — see
 * NATIVE-UI-CONTRACT.md §4c and §4d.
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

    /**
     * @param int|float $size Cast to float, because that is what reaches the wire —
     *                        upstream's setter casts too, and the content hash is a
     *                        `serialize()` so 24 and 24.0 hash differently.
     */
    public function fontSize(int|float $size): self
    {
        $this->textProps['font_size'] = (float) $size;

        return $this;
    }

    /** @param int|string $weight An ordinal on the wire; a string passes through unchanged. */
    public function fontWeight(string|int $weight): self
    {
        $this->textProps['font_weight'] = $weight;

        return $this;
    }

    public function color(string $color): self
    {
        $this->textProps['color'] = $color;

        return $this;
    }

    /**
     * @param int $align An enum ordinal, not a name — the wire carries integers here
     *                   (`text-center` parses to 2). Names belong in the style parser,
     *                   which is where upstream resolves them too.
     */
    public function textAlign(int $align): self
    {
        $this->textProps['text_align'] = $align;

        return $this;
    }

    public function maxLines(int $lines): self
    {
        $this->textProps['max_lines'] = $lines;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->textProps;
    }
}
