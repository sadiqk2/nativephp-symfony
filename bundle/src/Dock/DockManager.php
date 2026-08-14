<?php

declare(strict_types=1);

namespace Native\Symfony\Dock;

use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Enums\DockBounce;
use Native\Symfony\Menu\Menu;
use Native\Symfony\Support\Platform;

/**
 * The macOS dock — 8 endpoints.
 *
 * Every one of these calls app.dock.* in the runtime, which **is undefined on
 * Windows and Linux**: the request throws there rather than no-oping. So each
 * method guards on the platform and returns quietly instead, which keeps calling
 * code free of platform checks.
 */
final class DockManager
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Platform $platform,
    ) {
    }

    public function menu(Menu $menu): void
    {
        if (!$this->platform->isMac()) {
            return;
        }

        $this->client->post('dock', ['items' => $menu->toArray()]);
    }

    public function show(): void
    {
        if ($this->platform->isMac()) {
            $this->client->post('dock/show');
        }
    }

    public function hide(): void
    {
        if ($this->platform->isMac()) {
            $this->client->post('dock/hide');
        }
    }

    public function icon(string $path): void
    {
        if ($this->platform->isMac()) {
            $this->client->post('dock/icon', ['path' => $path]);
        }
    }

    public function badge(): string
    {
        if (!$this->platform->isMac()) {
            return '';
        }

        return (string) $this->client->get('dock/badge')->value('label', '');
    }

    public function setBadge(string $label): void
    {
        if ($this->platform->isMac()) {
            $this->client->post('dock/badge', ['label' => $label]);
        }
    }

    /**
     * Critical bounces until the app is activated; informational bounces once.
     * The runtime stores the bounce id so cancelBounce() can stop it — but it
     * keeps only the most recent one, so overlapping bounces cannot all be
     * cancelled.
     */
    public function bounce(DockBounce $type = DockBounce::Informational): void
    {
        if ($this->platform->isMac()) {
            $this->client->post('dock/bounce', ['type' => $type->value]);
        }
    }

    public function cancelBounce(): void
    {
        if ($this->platform->isMac()) {
            $this->client->post('dock/cancel-bounce');
        }
    }
}
