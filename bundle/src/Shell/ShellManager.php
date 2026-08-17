<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Shell;

use Native\Symfony\Desktop\Contract\ClientInterface;

/**
 * Desktop integration — 4 endpoints.
 *
 * Every one of these hands a user-supplied path or URL to the OS. Treat them as a
 * privilege boundary: openExternal on an attacker-controlled URL can launch
 * arbitrary registered protocol handlers, and openPath can execute a file.
 */
final class ShellManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** Reveal the item in Finder / Explorer / the file manager. */
    public function showInFolder(string $path): void
    {
        $this->client->post('shell/show-item-in-folder', ['path' => $path]);
    }

    /**
     * Open a path with its default application.
     *
     * @return string Empty on success; the OS error message on failure. The
     *                runtime answers 200 either way, so this string is the only
     *                signal.
     */
    public function openPath(string $path): string
    {
        $response = $this->client->post('shell/open-item', ['path' => $path]);

        // An empty string is this endpoint's "it worked", so a failed call must not
        // be allowed to produce one by defaulting.
        if (!$response->successful()) {
            return sprintf('The runtime answered %d.', $response->status);
        }

        return (string) $response->value('result', '');
    }

    /** Open a URL in the user's default browser. */
    public function openExternal(string $url): bool
    {
        return $this->client->post('shell/open-external', ['url' => $url])->successful();
    }

    /** Move to the trash rather than deleting. Returns false when the OS refused. */
    public function trash(string $path): bool
    {
        return $this->client->delete('shell/trash-item', ['path' => $path])->successful();
    }
}
