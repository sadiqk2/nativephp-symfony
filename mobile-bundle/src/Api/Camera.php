<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Camera and media picker.
 *
 * Every method here is asynchronous: the user has to point a camera or choose a
 * file, so the result arrives as an event. A true return means the UI was
 * presented, not that anything was captured.
 */
final class Camera
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function photo(?int $quality = null, bool $front = false): bool
    {
        $payload = ['front' => $front];

        if (null !== $quality) {
            $payload['quality'] = max(1, min(100, $quality));
        }

        return $this->bridge->dispatch('Camera.GetPhoto', $payload);
    }

    /**
     * @param 'image'|'video'|'all' $type
     * @param int                   $maxItems Cap on a multiple selection; ignored when $multiple is false
     */
    public function pickMedia(string $type = 'all', bool $multiple = false, int $maxItems = 10): bool
    {
        return $this->bridge->dispatch('Camera.PickMedia', [
            'mediaType' => $type,
            'multiple' => $multiple,
            'maxItems' => $maxItems,
        ]);
    }

    /** @param int|null $maxSeconds Recording cap; the OS may impose its own */
    public function recordVideo(?int $maxSeconds = null, bool $front = false): bool
    {
        $payload = ['front' => $front];

        if (null !== $maxSeconds) {
            $payload['maxDuration'] = $maxSeconds;
        }

        return $this->bridge->dispatch('Camera.RecordVideo', $payload);
    }
}
