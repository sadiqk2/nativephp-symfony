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
            $el->imageProps['source'] = $source;
        }

        return $el;
    }

    /** contain, cover, fill, none */
    public function fit(string $fit): self
    {
        $this->imageProps['fit'] = $fit;

        return $this;
    }

    protected function resolvedProps(\Native\Symfony\Mobile\Ui\CallbackRegistry $registry): array
    {
        return $this->imageProps;
    }
}
