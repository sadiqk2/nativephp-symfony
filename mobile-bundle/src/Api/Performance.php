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

    public function simulatePress(string $target): bool
    {
        return $this->bridge->dispatch('Perf.SimulatePress', ['target' => $target]);
    }

    public function simulateTextChange(string $target, string $value): bool
    {
        return $this->bridge->dispatch('Perf.SimulateTextChange', ['target' => $target, 'value' => $value]);
    }

    public function simulateToggle(string $target, bool $value): bool
    {
        return $this->bridge->dispatch('Perf.SimulateToggle', ['target' => $target, 'value' => $value]);
    }
}
