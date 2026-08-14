<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Bridge;

/**
 * Records calls and returns canned replies.
 *
 * Exists because the real bridge is a compiled PHP extension that only exists
 * inside a packaged app: without this, none of the mobile API could be tested off
 * a device at all. Ship it rather than keep it in tests/, so applications can use
 * it in their own suites.
 */
final class FakeBridge implements BridgeInterface
{
    /** @var list<array{method: string, payload: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, string|null> */
    private array $replies = [];

    public function __construct(public bool $available = true)
    {
    }

    /** @param array<string, mixed>|string|null $reply */
    public function willReturn(string $method, array|string|null $reply): void
    {
        $this->replies[$method] = \is_array($reply)
            ? json_encode($reply, \JSON_THROW_ON_ERROR)
            : $reply;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function call(string $method, array $payload = []): ?array
    {
        $raw = $this->raw($method, $payload);

        if (null === $raw || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    public function dispatch(string $method, array $payload = []): bool
    {
        if (!$this->available) {
            return false;
        }

        $this->raw($method, $payload);

        return true;
    }

    public function raw(string $method, array $payload = []): ?string
    {
        $this->calls[] = ['method' => $method, 'payload' => $payload];

        return $this->available ? ($this->replies[$method] ?? null) : null;
    }

    /** @return array{method: string, payload: array<string, mixed>} */
    public function lastCall(): array
    {
        if ([] === $this->calls) {
            throw new \LogicException('No native calls were recorded.');
        }

        return $this->calls[\count($this->calls) - 1];
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_map(static fn (array $c): string => $c['method'], $this->calls);
    }
}
