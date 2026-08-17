<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\System;

use Native\Symfony\Desktop\Contract\ClientInterface;
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
    /**
     * Whether a record is being forwarded right now.
     *
     * The loop this closes is real and lands only in a packaged app. `/api/debug` is
     * mounted `if (process.env.NODE_ENV === 'development')`, so in a build there is no
     * route: express answers with its default handler's *HTML* page rather than the
     * status phrase, `Client` cannot parse it as JSON, and it says so — through the
     * `native` channel's logger. If this logger is a handler on that channel, which is
     * exactly what the documentation suggests, that error posts another record, which
     * 404s, which logs. Every turn makes a blocking HTTP request, so it does not even
     * overflow the stack: the request simply never returns.
     */
    private bool $forwarding = false;

    /**
     * Set once the runtime has said there is no debug route.
     *
     * The routes are mounted at boot or never, so a 404 is permanent. Latching also
     * removes a blocking round trip per log record from every packaged app — the
     * "harmless 404 into the void" was never free.
     */
    private bool $unavailable = false;

    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function log($level, $message, array $context = []): void
    {
        if ($this->forwarding || $this->unavailable || !$this->client->isAvailable()) {
            return;
        }

        $this->forwarding = true;

        try {
            $response = $this->client->post('debug/log', [
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ]);

            if (404 === $response->status) {
                $this->unavailable = true;
            }
        } catch (\Throwable) {
            // Logging must never be the thing that breaks a request.
        } finally {
            $this->forwarding = false;
        }
    }
}
