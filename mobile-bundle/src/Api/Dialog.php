<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class Dialog
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** A transient message. 'short' or 'long' — Android durations; iOS approximates. */
    public function toast(string $message, string $duration = 'long'): bool
    {
        return $this->bridge->dispatch('Dialog.Toast', [
            'message' => $message,
            'duration' => $duration,
        ]);
    }
}
