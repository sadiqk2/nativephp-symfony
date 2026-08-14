<?php

declare(strict_types=1);

namespace Native\Symfony\Testing;

/**
 * One request the app made to the runtime.
 *
 * Kept as a value object rather than an array so failure messages have a single
 * place to decide how a call is rendered — the readability of an assertion
 * failure is most of this kit's value.
 */
final class RecordedCall
{
    /**
     * @param int                     $sequence 1-based position in the call log, quoted in failure messages
     * @param string                  $method   GET, POST or DELETE — the only three verbs the transport has
     * @param string                  $endpoint Runtime endpoint, without the /api/ prefix (e.g. "window/open")
     * @param array<array-key, mixed> $payload  Query parameters for GET, the JSON body otherwise
     */
    public function __construct(
        public readonly int $sequence,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly array $payload = [],
    ) {
    }

    /**
     * @param string $pattern An endpoint, optionally with `*` wildcards —
     *                        `window/get/*` matches every id, which matters
     *                        because several endpoints carry a value in the path
     */
    public function matchesEndpoint(string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return $this->endpoint === $pattern;
        }

        return 1 === preg_match('#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#', $this->endpoint);
    }

    /**
     * Does the payload contain at least these keys and values?
     *
     * Nested string-keyed arrays are compared as subsets too, so a caller can
     * pin one key of a 39-key window/open payload. Lists are compared whole:
     * a partial `cmd` or `properties` would be misleading rather than lenient,
     * since order is part of their meaning.
     *
     * @param array<array-key, mixed> $subset
     */
    public function payloadContains(array $subset): bool
    {
        return self::subsetOf($subset, $this->payload);
    }

    /** Single-line rendering used in assertion failure messages. */
    public function describe(): string
    {
        return sprintf('#%d %-6s %-32s %s', $this->sequence, $this->method, $this->endpoint, self::encode($this->payload));
    }

    /**
     * @param array<array-key, mixed> $subset
     * @param array<array-key, mixed> $actual
     */
    private static function subsetOf(array $subset, array $actual): bool
    {
        foreach ($subset as $key => $expected) {
            if (!\array_key_exists($key, $actual)) {
                return false;
            }

            $found = $actual[$key];

            if (\is_array($expected) && \is_array($found) && !array_is_list($expected)) {
                if (!self::subsetOf($expected, $found)) {
                    return false;
                }

                continue;
            }

            if ($expected !== $found) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $payload */
    private static function encode(array $payload): string
    {
        if ([] === $payload) {
            return '(no payload)';
        }

        $json = json_encode(
            $payload,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return false === $json ? '(unencodable payload)' : $json;
    }
}
