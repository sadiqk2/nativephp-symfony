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
 *
 * Deliberately not more permissive than the real thing: a payload it cannot encode
 * throws instead of being recorded, and a reply that is empty, whitespace or not
 * JSON reads as nothing — both exactly as `Bridge` behaves.
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

        // Trimmed and strict, exactly as Bridge::call() reads it. A whitespace-only or
        // non-JSON reply is "the native side said nothing", and reading it as
        // ['value' => null] handed the app a shape it can never see on a device:
        // Device::info() is `call(...) ?? []`, and an empty array is falsy where
        // ['value' => null] is not.
        if (null === $raw || '' === trim($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

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
        if ($this->available) {
            $this->refuseUnencodablePayload($method, $payload);
        }

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

    /**
     * The wire is JSON, so a payload that cannot be encoded is a call that never
     * happens: Bridge::raw() encodes with JSON_THROW_ON_ERROR and turns a failure
     * into an InvalidArgumentException. Recording it here instead let an assertion
     * on lastCall() pass for code that throws the moment the app runs on a device —
     * an app reaches this with a filename, a scanned barcode or a database column
     * that is not UTF-8. Only when the bridge is available, because the real one
     * returns before it encodes anything when it is not.
     *
     * @param array<string, mixed> $payload
     */
    private function refuseUnencodablePayload(string $method, array $payload): void
    {
        try {
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                sprintf('Payload for native method "%s" is not JSON-encodable: %s', $method, $e->getMessage()),
                previous: $e,
            );
        }
    }
}
