<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Elements;

use Native\Symfony\Mobile\Ui\Element;

/** A remote or bundled image. */
final class Image extends Element
{
    protected string $type = 'image';

    /** @var array<string, mixed> */
    private array $imageProps = [];

    public static function make(string $source = ''): self
    {
        $el = new self();

        if ('' !== $source) {
            // `src`, not `source`: it is interned at index 14 of the PropKey table
            // in both renderers (NativeUINode.swift, NativeUINode.kt) and `source`
            // is not in the table at all, so it took the generic fallback path and
            // the image renderer's typed slot stayed empty. Every image was blank.
            $el->imageProps['src'] = $source;
        }

        return $el;
    }

    /**
     * The parsed `object-*` mode, as the wire enum the renderers read.
     *
     * An int rather than a string: StyleParser emits `['fit' => 2]` for
     * `object-cover`, and with a string parameter the call from StyleApplier threw
     * a TypeError that its own catch discarded — so those classes did nothing.
     */
    public function fit(int $fit): self
    {
        $this->imageProps['fit'] = $fit;

        return $this;
    }

    public function tintColor(string $color): self
    {
        $this->imageProps['tint_color'] = $color;

        return $this;
    }

    public function alt(string $alt): self
    {
        $this->imageProps['alt'] = $alt;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->imageProps;
    }
}
