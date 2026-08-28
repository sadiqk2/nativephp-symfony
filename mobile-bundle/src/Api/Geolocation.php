<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Location, including background watching.
 *
 * Every method here addresses one watch by its id, because several can run at once
 * and the ids come from whatever started them.
 *
 * Background fixes are buffered natively rather than pushed one by one — a phone
 * can accumulate thousands while the app is asleep, and waking PHP for each would
 * drain the battery. The buffer is append-only and paged by a byte offset, so the
 * app reads only what is new: drain from a cursor, persist what came back, then
 * trim up to the cursor the drain returned. This is why it reads differently from
 * a browser's geolocation API.
 */
final class Geolocation
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /**
     * The background watch currently persisted natively, or null when none is.
     *
     * A freshly booted runtime uses this to discover a watch that outlived it —
     * background stream, process death, reboot re-arm — and re-attach to it.
     *
     * @return array<string, mixed>|null Its id, event, minDistance, fineAccuracy and bufferBytes
     */
    public function backgroundWatchStatus(): ?array
    {
        $result = $this->bridge->call('Geolocation.BackgroundWatchStatus');

        if (!\is_array($result) || true !== ($result['active'] ?? false)) {
            return null;
        }

        unset($result['active']);

        return $result;
    }

    /**
     * Stop a background watch.
     *
     * The buffered fixes survive by default so a final drainWatch() can collect the
     * tail of the stream; pass $clearBuffer to delete them with the watch.
     */
    public function stopBackgroundWatch(string $id, bool $clearBuffer = false): bool
    {
        return $this->bridge->dispatch('Geolocation.StopBackgroundWatch', [
            'id' => $id,
            'clearBuffer' => $clearBuffer,
        ]);
    }

    /** Stop a foreground watch. A no-op natively for an id that is not watching. */
    public function clearWatch(string $id): bool
    {
        return $this->bridge->dispatch('Geolocation.ClearWatch', ['id' => $id]);
    }

    /**
     * Read the fixes one watch buffered while PHP was not running.
     *
     * Non-destructive: $cursor is a byte offset into the append-only native buffer,
     * so persist the cursor that comes back and pass it in next time to read only
     * what is new. Reclaim the space with trimWatch() once the fixes are safely
     * stored — offsets rebase after a trim, so start again from 0.
     *
     * @return array{fixes: list<array<string, mixed>>, cursor: int, size: int}
     */
    public function drainWatch(string $id, int $cursor = 0): array
    {
        $result = $this->bridge->call('Geolocation.DrainWatchBuffer', [
            'id' => $id,
            'cursor' => $cursor,
        ]);

        if (!\is_array($result) || !\is_array($result['fixes'] ?? null)) {
            return ['fixes' => [], 'cursor' => $cursor, 'size' => 0];
        }

        return [
            'fixes' => array_values(array_filter($result['fixes'], 'is_array')),
            'cursor' => (int) ($result['cursor'] ?? $cursor),
            'size' => (int) ($result['size'] ?? 0),
        ];
    }

    /**
     * Discard one watch's buffered fixes up to a byte offset.
     *
     * Destructive: pass only the cursor a drainWatch() returned, and only once what
     * it returned is durably stored. Without trimming, the buffer grows until the
     * watch is stopped with clearBuffer.
     */
    public function trimWatch(string $id, int $upTo): bool
    {
        return $this->bridge->dispatch('Geolocation.TrimWatchBuffer', [
            'id' => $id,
            'upTo' => $upTo,
        ]);
    }
}
