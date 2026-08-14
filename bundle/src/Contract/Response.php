<?php

declare(strict_types=1);

namespace Native\Symfony\Contract;

/**
 * A runtime reply.
 *
 * Exists because the runtime's responses come in three shapes that a caller has
 * to tell apart (CONTRACT.md §0): a bare 200 with no body, a 200 with a JSON
 * object, and — for menu-bar and context-menu endpoints — a 200 sent *before*
 * the work happens, which acknowledges receipt and promises nothing.
 */
final class Response
{
    /** @param array<string, mixed>|list<mixed>|null $data */
    public function __construct(
        public readonly int $status,
        public readonly ?array $data = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed>|list<mixed> */
    public function array(): array
    {
        return $this->data ?? [];
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
