<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\App;

use Native\Symfony\Desktop\Contract\ClientInterface;

/**
 * All 19 app endpoints (CONTRACT.md §2).
 */
final class AppManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function quit(): void
    {
        $this->client->post('app/quit');
    }

    /**
     * Fire-and-forget: the runtime relaunches and quits without ever sending a
     * response, so awaiting this would block until the process dies. The
     * transport exception that follows is swallowed on purpose.
     */
    public function relaunch(): void
    {
        try {
            $this->client->post('app/relaunch');
        } catch (\Throwable) {
            // Expected — see above.
        }
    }

    public function show(): void
    {
        $this->client->post('app/show');
    }

    public function hide(): void
    {
        $this->client->post('app/hide');
    }

    public function isHidden(): bool
    {
        return (bool) $this->client->get('app/is-hidden')->value('is_hidden', false);
    }

    public function locale(): string
    {
        return (string) $this->client->get('app/locale')->value('locale', '');
    }

    public function localeCountryCode(): string
    {
        return (string) $this->client->get('app/locale-country-code')->value('locale_country_code', '');
    }

    public function systemLocale(): string
    {
        return (string) $this->client->get('app/system-locale')->value('system_locale', '');
    }

    public function version(): string
    {
        return (string) $this->client->get('app/version')->value('version', '');
    }

    public function appPath(): string
    {
        return (string) $this->client->get('app/app-path')->value('path', '');
    }

    /**
     * @param AppPath|string $name One of Electron's app.getPath() names. An
     *                             unrecognised name throws inside the runtime.
     */
    public function path(AppPath|string $name): string
    {
        $name = $name instanceof AppPath ? $name->value : $name;

        return (string) $this->client->get('app/path/'.$name)->value('path', '');
    }

    public function badgeCount(): int
    {
        return (int) $this->client->get('app/badge-count')->value('count', 0);
    }

    public function setBadgeCount(int $count): void
    {
        $this->client->post('app/badge-count', ['count' => $count]);
    }

    public function addRecentDocument(string $path): void
    {
        $this->client->post('app/recent-documents', ['path' => $path]);
    }

    public function clearRecentDocuments(): void
    {
        $this->client->delete('app/recent-documents');
    }

    public function opensAtLogin(): bool
    {
        return (bool) $this->client->get('app/open-at-login')->value('open', false);
    }

    public function openAtLogin(bool $open = true): void
    {
        $this->client->post('app/open-at-login', ['open' => $open]);
    }

    public function isEmojiPanelSupported(): bool
    {
        return (bool) $this->client->get('app/is-emoji-panel-supported')->value('supported', false);
    }

    public function showEmojiPanel(): void
    {
        $this->client->post('app/show-emoji-panel');
    }
}
