<?php

declare(strict_types=1);

namespace App\Note;

final class Note
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $body,
        public readonly int $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? bin2hex(random_bytes(4))),
            (string) ($data['title'] ?? 'Untitled'),
            (string) ($data['body'] ?? ''),
            (int) ($data['createdAt'] ?? time()),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'createdAt' => $this->createdAt,
        ];
    }

    public function asText(): string
    {
        return $this->title."\n\n".$this->body;
    }
}
