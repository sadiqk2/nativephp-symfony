<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Native file moves and copies.
 *
 * Only needed where PHP cannot reach: content:// URIs on Android, security-scoped
 * URLs on iOS. Ordinary paths inside the app's own sandbox are better handled with
 * PHP's own filesystem functions or Symfony's Filesystem component.
 */
final class Files
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function copy(string $from, string $to): bool
    {
        $result = $this->bridge->call('File.Copy', ['from' => $from, 'to' => $to]);

        return (bool) ($result['success'] ?? false);
    }

    public function move(string $from, string $to): bool
    {
        $result = $this->bridge->call('File.Move', ['from' => $from, 'to' => $to]);

        return (bool) ($result['success'] ?? false);
    }
}
