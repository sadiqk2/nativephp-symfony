<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class Share
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function url(string $url, string $title = '', string $text = ''): bool
    {
        return $this->bridge->dispatch('Share.Url', [
            'url' => $url,
            'title' => $title,
            'text' => $text,
        ]);
    }

    /** @param string $path An absolute on-device path the app can read */
    public function file(string $path, string $title = '', string $text = ''): bool
    {
        return $this->bridge->dispatch('Share.File', [
            'path' => $path,
            'title' => $title,
            'text' => $text,
        ]);
    }
}
