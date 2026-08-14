<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class Network
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** @return array<string, mixed> Connectivity and connection type */
    public function status(): array
    {
        return $this->bridge->call('Network.Status') ?? [];
    }

    /**
     * Whether the device believes it has a connection.
     *
     * Defaults to true when unknown: a false would make an app refuse to try, and
     * an attempt that fails is better than an attempt never made.
     */
    public function isConnected(): bool
    {
        $status = $this->status();

        return (bool) ($status['connected'] ?? $status['isConnected'] ?? true);
    }

    public function connectionType(): ?string
    {
        $status = $this->status();
        $type = $status['type'] ?? $status['connectionType'] ?? null;

        return \is_string($type) ? $type : null;
    }
}
