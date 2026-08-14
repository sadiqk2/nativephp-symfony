<?php

declare(strict_types=1);

namespace Native\Symfony\System;

use Native\Symfony\Contract\ClientInterface;
use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that forwards records into the renderer's devtools console, so
 * server-side logs and front-end logs land in one place while developing.
 *
 * Register it as a Monolog handler to mirror the app's log. Note the runtime only
 * mounts /api/debug when NODE_ENV is development — in a packaged build these posts
 * 404 into the void, harmlessly.
 */
final class DebugLogger extends AbstractLogger
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function log($level, $message, array $context = []): void
    {
        if (!$this->client->isAvailable()) {
            return;
        }

        try {
            $this->client->post('debug/log', [
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ]);
        } catch (\Throwable) {
            // Logging must never be the thing that breaks a request.
        }
    }
}
