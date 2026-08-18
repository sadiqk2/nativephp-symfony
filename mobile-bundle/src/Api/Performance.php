<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * The native performance instrumentation, including its UI-simulation hooks.
 *
 * Development tooling. The simulate* methods drive the UI from PHP, which is
 * exactly the primitive an automated device test needs — and exactly what should
 * never be reachable in a shipped build.
 */
final class Performance
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function enable(): bool
    {
        return $this->bridge->dispatch('Perf.Enable');
    }

    public function disable(): bool
    {
        return $this->bridge->dispatch('Perf.Disable');
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        return $this->bridge->call('Perf.Export') ?? [];
    }

    public function showFpsOverlay(bool $enabled = true): bool
    {
        return $this->bridge->dispatch('Perf.SetFpsOverlayEnabled', ['enabled' => $enabled]);
    }

    public function startCaptureWindow(?string $label = null): bool
    {
        return $this->bridge->dispatch('Perf.StartCaptureWindow', null === $label ? [] : ['label' => $label]);
    }

    public function stopCaptureWindow(): bool
    {
        return $this->bridge->dispatch('Perf.StopCaptureWindow');
    }

    /**
     * Simulate a press on a published node.
     *
     * These three took a string `$target` and sent it as `target`, which neither host
     * reads: both require `callback_id` as a number and return `success: false` without
     * it, so every call did nothing on a device while succeeding in PHP. The callback id
     * is the one the frame published — `ComponentScreen::callbackId()` is how a test gets
     * it — and `$nodeId` is optional on both sides, defaulting to 0.
     */
    public function simulatePress(int $callbackId, int $nodeId = 0): bool
    {
        return $this->bridge->dispatch('Perf.SimulatePress', [
            'callback_id' => $callbackId,
            'node_id' => $nodeId,
        ]);
    }

    /** @see simulatePress() for why this takes a callback id */
    public function simulateTextChange(int $callbackId, string $text, int $nodeId = 0): bool
    {
        return $this->bridge->dispatch('Perf.SimulateTextChange', [
            'callback_id' => $callbackId,
            'node_id' => $nodeId,
            'text' => $text,
        ]);
    }

    /** @see simulatePress() for why this takes a callback id */
    public function simulateToggle(int $callbackId, bool $value, int $nodeId = 0): bool
    {
        return $this->bridge->dispatch('Perf.SimulateToggle', [
            'callback_id' => $callbackId,
            'node_id' => $nodeId,
            'value' => $value,
        ]);
    }
}
