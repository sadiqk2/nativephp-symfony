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
