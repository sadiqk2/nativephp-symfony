<?php

namespace App\Native;

use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Window\WindowManager;

/**
 * The entire app-startup contract. The runtime never opens a window on its own — it
 * POSTs /_native/api/booted when it is ready, and this is what runs. No wiring is
 * needed: the bundle autoconfigures the interface and a compiler pass aliases it.
 *
 * `boot()` must be idempotent — macOS re-posts /booted on `activate` — and
 * `WindowManager::open()` is already idempotent by id, so a `boot()` that only opens
 * windows needs no guard of its own.
 */
final class Bootstrapper implements AppBootstrapper
{
    public function __construct(private readonly WindowManager $windows) {}

    public function boot(): void
    {
        $this->windows->open('main')->url('/')->size(900, 600)->title('Quickstart')->open();
    }
}
