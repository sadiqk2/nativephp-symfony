<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Audio recording.
 *
 * A stateful native session: start, optionally pause and resume, stop, then
 * collect. Calling getRecording() before stop() returns nothing rather than a
 * partial file.
 */
final class Microphone
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function start(): bool
    {
        return $this->bridge->dispatch('Microphone.Start');
    }

    public function stop(): bool
    {
        return $this->bridge->dispatch('Microphone.Stop');
    }

    public function pause(): bool
    {
        return $this->bridge->dispatch('Microphone.Pause');
    }

    public function resume(): bool
    {
        return $this->bridge->dispatch('Microphone.Resume');
    }

    /** @return array<string, mixed> Recording state, elapsed time, permission state */
    public function status(): array
    {
        return $this->bridge->call('Microphone.GetStatus') ?? [];
    }

    public function isRecording(): bool
    {
        return (bool) ($this->status()['recording'] ?? false);
    }

    /** @return array<string, mixed> The finished recording — path, duration, format */
    public function recording(): array
    {
        return $this->bridge->call('Microphone.GetRecording') ?? [];
    }
}
