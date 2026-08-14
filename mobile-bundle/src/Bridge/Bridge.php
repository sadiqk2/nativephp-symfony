<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Bridge;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Bridge implements BridgeInterface
{
    private readonly LoggerInterface $logger;

    /** @var (callable(string, string): (string|null))|null */
    private readonly mixed $invoker;

    /**
     * @param (callable(string, string): (string|null))|null $invoker Stands in for the
     *        nativephp_call() extension function. Real apps leave this null; supplying
     *        it is how this class is tested at all, since the extension exists only
     *        inside a packaged app and cannot be loaded here.
     */
    public function __construct(?LoggerInterface $logger = null, ?callable $invoker = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->invoker = $invoker;
    }

    public function isAvailable(): bool
    {
        return null !== $this->invoker || \function_exists('nativephp_call');
    }

    public function call(string $method, array $payload = []): ?array
    {
        $raw = $this->raw($method, $payload);

        if (null === $raw || '' === trim($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Same discipline as the desktop client: never let "the native layer
            // did not answer" look identical to "the native layer said nothing".
            $this->logger->error('Native method {method} returned non-JSON: {error}', [
                'method' => $method,
                'error' => $e->getMessage(),
                'raw' => substr($raw, 0, 512),
            ]);

            return null;
        }

        return \is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    public function dispatch(string $method, array $payload = []): bool
    {
        if (!$this->isAvailable()) {
            $this->logger->debug('Native method {method} skipped: bridge unavailable.', ['method' => $method]);

            return false;
        }

        $this->raw($method, $payload);

        return true;
    }

    public function raw(string $method, array $payload = []): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                sprintf('Payload for native method "%s" is not JSON-encodable: %s', $method, $e->getMessage()),
                previous: $e,
            );
        }

        try {
            $result = null !== $this->invoker
                ? ($this->invoker)($method, $json)
                : nativephp_call($method, $json);
        } catch (\Throwable $e) {
            // A throw from the extension means the native side failed. Surfacing it
            // as an exception in a controller is usually worse than a null, since
            // most of these are optional capabilities.
            $this->logger->error('Native method {method} threw: {error}', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return \is_string($result) ? $result : null;
    }
}
