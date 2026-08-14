<?php

declare(strict_types=1);

namespace Native\Symfony\Screen;

use Native\Symfony\Contract\ClientInterface;

/**
 * Displays and cursor position — 4 endpoints.
 *
 * Two of these are the only endpoints in the whole API that return an *unwrapped*
 * object rather than a `{key: …}` envelope, so they are read differently below.
 */
final class ScreenManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** @return list<array<string, mixed>> Electron Display objects */
    public function displays(): array
    {
        $displays = $this->client->get('screen/displays')->value('displays', []);

        return \is_array($displays) ? array_values(array_filter($displays, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    public function primaryDisplay(): array
    {
        $display = $this->client->get('screen/primary-display')->value('primaryDisplay', []);

        return \is_array($display) ? $display : [];
    }

    /**
     * @return array{x: int, y: int}
     *
     * Unwrapped: the runtime returns the Point directly, not {cursorPosition: …}.
     */
    public function cursorPosition(): array
    {
        $point = $this->client->get('screen/cursor-position')->array();

        return [
            'x' => (int) ($point['x'] ?? 0),
            'y' => (int) ($point['y'] ?? 0),
        ];
    }

    /**
     * The display nearest the cursor. Also unwrapped.
     *
     * @return array<string, mixed>
     */
    public function activeDisplay(): array
    {
        return (array) $this->client->get('screen/active')->array();
    }
}
