<?php

declare(strict_types=1);

namespace Native\Symfony\Contract;

/**
 * Channel A: the app's side of the 116-endpoint runtime API.
 *
 * Deliberately narrow — three verbs and an availability probe. The runtime
 * speaks JSON over loopback HTTP with a shared-secret header and nothing else,
 * so anything richer would be inventing structure the wire does not have.
 *
 * A first candidate for extraction into a framework-neutral nativephp/core.
 */
interface ClientInterface
{
    /**
     * False when the app is running as an ordinary web request rather than
     * inside the runtime — NATIVEPHP_API_URL is absent. Callers that may run in
     * both contexts must check this; every request otherwise fails.
     */
    public function isAvailable(): bool;

    /** @param array<string, scalar|null> $query */
    public function get(string $endpoint, array $query = []): Response;

    /** @param array<string, mixed> $data */
    public function post(string $endpoint, array $data = []): Response;

    /** @param array<string, mixed> $data */
    public function delete(string $endpoint, array $data = []): Response;
}
