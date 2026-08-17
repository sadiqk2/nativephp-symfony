<?php

declare(strict_types=1);

namespace App\Note;

use Native\Symfony\Desktop\Settings\SettingsManager;

/**
 * The app's persistence, such as it is: the runtime's own settings store.
 *
 * This is the interesting half of "settings persistence" — not that a key can be
 * written, but that a desktop app gets a durable, per-user store for free. It is
 * electron-store under the hood: a JSON file in the platform's userData directory,
 * surviving restarts, with no database and no schema.
 *
 * Two things this class exists to encode:
 *
 * 1. **The key has no dots.** electron-store uses dot-prop, so `deskpad.notes`
 *    would be stored as `{"deskpad":{"notes":…}}` and the SettingChanged event
 *    would report the root key `deskpad`, not the key that was written. A colon
 *    keeps the event and the write talking about the same thing.
 * 2. **Every write fires SettingChanged, including ours.** A listener that writes
 *    on change would loop, so {@see \App\EventListener\RuntimeEventLogger} only
 *    ever logs it.
 */
final class NoteStore
{
    private const KEY = 'deskpad:notes';

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    /** @return list<Note> Newest first */
    public function all(): array
    {
        $raw = $this->settings->get(self::KEY, []);

        if (\is_string($raw)) {
            // Belt and braces: the store round-trips arrays fine, but a hand-edited
            // JSON file should not take the app down.
            $raw = json_decode($raw, true);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $notes = array_map(
            static fn (mixed $row): Note => Note::fromArray(\is_array($row) ? $row : []),
            array_values(array_filter($raw, 'is_array')),
        );

        usort($notes, static fn (Note $a, Note $b): int => $b->createdAt <=> $a->createdAt);

        return $notes;
    }

    public function latest(): ?Note
    {
        return $this->all()[0] ?? null;
    }

    public function find(string $id): ?Note
    {
        foreach ($this->all() as $note) {
            if ($note->id === $id) {
                return $note;
            }
        }

        return null;
    }

    public function add(string $title, string $body): Note
    {
        $note = new Note(bin2hex(random_bytes(4)), $title, $body, time());

        $this->write([$note, ...$this->all()]);

        return $note;
    }

    public function remove(string $id): void
    {
        $this->write(array_values(array_filter(
            $this->all(),
            static fn (Note $note): bool => $note->id !== $id,
        )));
    }

    public function count(): int
    {
        return \count($this->all());
    }

    /** @param list<Note> $notes */
    private function write(array $notes): void
    {
        $this->settings->set(self::KEY, array_map(
            static fn (Note $note): array => $note->toArray(),
            $notes,
        ));
    }
}
