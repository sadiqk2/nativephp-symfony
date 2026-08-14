<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Contract\Response;

/**
 * Records calls so tests can assert on the wire payloads, which is where the
 * contract actually lives.
 */
final class FakeClient implements ClientInterface
{
    /** @var list<array{method: string, endpoint: string, data: array<mixed>}> */
    public array $calls = [];

    /** @var array<string, Response> */
    private array $canned = [];

    public function __construct(public bool $available = true)
    {
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public function willReturn(string $endpoint, array $data, int $status = 200): void
    {
        $this->canned[$endpoint] = new Response($status, $data);
    }

    public function willReturnStatus(string $endpoint, int $status): void
    {
        $this->canned[$endpoint] = new Response($status);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function get(string $endpoint, array $query = []): Response
    {
        return $this->record('GET', $endpoint, $query);
    }

    public function post(string $endpoint, array $data = []): Response
    {
        return $this->record('POST', $endpoint, $data);
    }

    public function delete(string $endpoint, array $data = []): Response
    {
        return $this->record('DELETE', $endpoint, $data);
    }

    /** @return array{method: string, endpoint: string, data: array<mixed>} */
    public function lastCall(): array
    {
        if ([] === $this->calls) {
            throw new \LogicException('No runtime calls were recorded.');
        }

        return $this->calls[\count($this->calls) - 1];
    }

    /** @param array<mixed> $data */
    private function record(string $method, string $endpoint, array $data): Response
    {
        $this->calls[] = ['method' => $method, 'endpoint' => $endpoint, 'data' => $data];

        return $this->canned[$endpoint] ?? new Response(200);
    }
}
