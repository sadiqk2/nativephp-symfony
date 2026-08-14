<?php

declare(strict_types=1);

namespace Native\Symfony\Shell;

use Native\Symfony\Contract\ClientInterface;

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
        return (string) $this->client->post('shell/open-item', ['path' => $path])->value('result', '');
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
