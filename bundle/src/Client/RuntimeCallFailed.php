<?php

declare(strict_types=1);

namespace Native\Symfony\Client;

final class RuntimeCallFailed extends \RuntimeException
{
    public static function transport(string $method, string $endpoint, \Throwable $previous): self
    {
        return new self(
            sprintf('Runtime call %s %s failed: %s', $method, $endpoint, $previous->getMessage()),
            0,
            $previous,
        );
    }

    /**
     * For a call whose *answer* cannot be trusted, as opposed to one that failed
     * to happen. Used where a wrong default would be worse than an exception —
     * a dialog whose result decides whether something gets deleted.
     */
    public static function badResponse(string $endpoint, int $status): self
    {
        return new self(sprintf(
            'Runtime call %s answered %d without a usable result. Refusing to guess: the caller '.
            'treats this answer as a decision, and a default would be the wrong one.',
            $endpoint,
            $status,
        ));
    }

    public static function forbidden(string $method, string $endpoint): self
    {
        return new self(sprintf(
            'Runtime call %s %s was rejected with 403 — the X-NativePHP-Secret header did not match. '.
            'NATIVEPHP_SECRET is generated per boot and handed only to processes the runtime spawns; a '.
            'stale value usually means this process outlived the runtime that started it.',
            $method,
            $endpoint,
        ));
    }
}
