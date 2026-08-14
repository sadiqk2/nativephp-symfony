<?php

declare(strict_types=1);

namespace Native\Symfony\System;

use Native\Symfony\Contract\ClientInterface;

/**
 * The taskbar / dock progress indicator.
 *
 * Note there is no window id: the runtime applies the value to **every** window.
 */
final class ProgressBar
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** @param float $percent 0.0–1.0. Values above 1 show an indeterminate bar. */
    public function update(float $percent): void
    {
        $this->client->post('progress-bar/update', ['percent' => $percent]);
    }

    /** Electron's convention for "no progress bar" is -1, not 0. */
    public function clear(): void
    {
        $this->client->post('progress-bar/update', ['percent' => -1]);
    }
}
