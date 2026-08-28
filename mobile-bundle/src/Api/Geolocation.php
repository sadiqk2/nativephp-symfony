<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Location, including background watching.
 *
 * A watch is addressed by its id, because several can run at once. The two methods that
 * start one return the id they minted; every other method takes it.
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
    /**
     * The event names upstream's Geolocation builders send, one per result shape.
     *
     * The hosts use them only as labels to echo back when the result arrives, so nothing
     * here has to be able to load them — they are Laravel class names for the same reason
     * Dialog's and Scanner's are.
     */
    public const LOCATION_RECEIVED = 'Native\\Mobile\\Events\\Geolocation\\LocationReceived';
    public const LOCATION_UPDATED = 'Native\\Mobile\\Events\\Geolocation\\LocationUpdated';
    public const PERMISSION_STATUS_RECEIVED = 'Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived';
    public const PERMISSION_REQUEST_RESULT = 'Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult';

    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /**
     * A single fix.
     *
     * Asynchronous: a true means the request reached the native layer, not that a
     * position was found. The fix arrives later as the event named by $event.
     *
     * @param bool        $fineAccuracy GPS rather than the battery-friendlier network location
     * @param string|null $id           Echoed back in the event so a listener can tell which
     *                                  request answered. Generated when absent, as upstream
     *                                  does, rather than left off the wire
     */
    public function currentPosition(bool $fineAccuracy = false, ?string $id = null, string $event = self::LOCATION_RECEIVED): bool
    {
        return $this->bridge->dispatch('Geolocation.GetCurrentPosition', [
            'id' => $id ?? $this->newId(),
            'event' => $event,
            'fineAccuracy' => $fineAccuracy,
        ]);
    }

    /**
     * Ask what location permission the app already has, without prompting.
     *
     * Asynchronous, like everything a user might have to answer: the status arrives as
     * the event named by $event. Carries no accuracy — there is none to choose when
     * nothing is being located, and upstream's payload for this method has none either.
     */
    public function checkPermissions(?string $id = null, string $event = self::PERMISSION_STATUS_RECEIVED): bool
    {
        return $this->bridge->dispatch('Geolocation.CheckPermissions', [
            'id' => $id ?? $this->newId(),
            'event' => $event,
        ]);
    }

    /** Prompt for location permission. The user's answer arrives as $event. */
    public function requestPermissions(?string $id = null, string $event = self::PERMISSION_REQUEST_RESULT): bool
    {
        return $this->bridge->dispatch('Geolocation.RequestPermissions', [
            'id' => $id ?? $this->newId(),
            'event' => $event,
        ]);
    }

    /**
     * Start a foreground stream of fixes, and return the id that addresses it.
     *
     * The id is the point: it is what clearWatch() and every other method here name, and
     * several watches can run at once. Returns null when nothing was started, which off a
     * device is the only outcome — there is no watch, so there is no id for one.
     *
     * Foreground only. The OS stops delivering fixes once the app is backgrounded, and
     * nothing here notices; use startBackgroundWatch() for a stream that must survive it.
     *
     * @param int   $interval    Target milliseconds between fixes. Paces Android's fused
     *                           provider; iOS is event-driven, so throttle it with
     *                           $minDistance instead
     * @param float $minDistance Metres the device must move before another fix
     */
    public function watchPosition(bool $fineAccuracy = false, int $interval = 5000, float $minDistance = 0.0, ?string $id = null, string $event = self::LOCATION_UPDATED): ?string
    {
        $id ??= $this->newId();

        // Clamped as upstream's interval() and minDistance() clamp: a negative pace is
        // not something either host has a meaning for.
        $started = $this->bridge->dispatch('Geolocation.WatchPosition', [
            'id' => $id,
            'event' => $event,
            'fineAccuracy' => $fineAccuracy,
            'interval' => max(0, $interval),
            'minDistance' => max(0.0, $minDistance),
        ]);

        return $started ? $id : null;
    }

    /**
     * Start a native background stream instead, and return the id that addresses it.
     *
     * Deliberately outlives the request that started it — process death and a reboot
     * included — so persist the id somewhere durable and stop it with
     * stopBackgroundWatch(). Fixes accumulate in the native buffer while PHP is not
     * running; drainWatch() collects them. A booted runtime that has lost the id can
     * still find the watch through backgroundWatchStatus().
     *
     * Android shows the OS-mandated persistent notification for the duration.
     */
    public function startBackgroundWatch(bool $fineAccuracy = false, int $interval = 5000, float $minDistance = 0.0, ?string $id = null, string $event = self::LOCATION_UPDATED): ?string
    {
        $id ??= $this->newId();

        $started = $this->bridge->dispatch('Geolocation.StartBackgroundWatch', [
            'id' => $id,
            'event' => $event,
            'fineAccuracy' => $fineAccuracy,
            'interval' => max(0, $interval),
            'minDistance' => max(0.0, $minDistance),
        ]);

        return $started ? $id : null;
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

    /** An id for a watch or a request that was not given one, as upstream generates one. */
    private function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
