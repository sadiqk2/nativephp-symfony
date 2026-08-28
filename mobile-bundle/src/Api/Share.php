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

    /**
     * A file share names its keys differently from a URL share: the sheet reads
     * `filePath` and `message`, not `path` and `text` (see upstream's own wrapper,
     * np-mobile src/Share.php). Sending the URL spelling opened a sheet with nothing
     * attached, and dispatch() still returned true.
     *
     * @param string $path An absolute on-device path the app can read
     */
    public function file(string $path, string $title = '', string $text = ''): bool
    {
        return $this->bridge->dispatch('Share.File', [
            'title' => $title,
            'message' => $text,
            'filePath' => $path,
        ]);
    }
}
