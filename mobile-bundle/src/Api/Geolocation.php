<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Location, including background watching.
 *
 * Background positions are buffered natively rather than pushed one by one — a
 * phone can accumulate thousands while the app is asleep, and waking PHP for each
 * would drain the battery. The app drains the buffer when it next runs, which is
 * why this reads differently from a browser's geolocation API.
 */
final class Geolocation
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** @return array<string, mixed> Whether background watching is active, and its permission state */
    public function backgroundWatchStatus(): array
    {
        return $this->bridge->call('Geolocation.BackgroundWatchStatus') ?? [];
    }

    public function stopBackgroundWatch(): bool
    {
        return $this->bridge->dispatch('Geolocation.StopBackgroundWatch');
    }

    public function clearWatch(): bool
    {
        return $this->bridge->dispatch('Geolocation.ClearWatch');
    }

    /**
     * Read and remove buffered positions.
     *
     * Destructive: a position returned here is gone from the buffer. Persist before
     * doing anything that might fail.
     *
     * @return list<array<string, mixed>>
     */
    public function drainWatchBuffer(?int $limit = null): array
    {
        $result = $this->bridge->call(
            'Geolocation.DrainWatchBuffer',
            null === $limit ? [] : ['limit' => $limit],
        );

        $positions = $result['positions'] ?? $result['locations'] ?? [];

        return \is_array($positions) ? array_values(array_filter($positions, 'is_array')) : [];
    }

    /** Drop buffered positions without reading them. */
    public function trimWatchBuffer(int $keep = 0): bool
    {
        return $this->bridge->dispatch('Geolocation.TrimWatchBuffer', ['keep' => $keep]);
    }
}
