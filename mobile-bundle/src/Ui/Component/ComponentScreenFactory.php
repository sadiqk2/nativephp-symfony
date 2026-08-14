<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

use Native\Symfony\Mobile\Ui\ElementPublisher;

/**
 * Opens screens with the container's publisher.
 *
 * A `ComponentScreen` cannot itself be a service: it needs a root component, which is
 * per-screen application state rather than something the container can build. This
 * factory is the seam — it is the service, and a controller or the native runloop asks
 * it for a screen.
 *
 * Note for whoever wires the DI: this factory and `ElementPublisher` are the services;
 * the screen it returns must be held by the caller for as long as the screen is
 * visible, and must **not** be tagged `kernel.reset`, or component state would be
 * cleared underneath a live screen at the end of a dispatch.
 */
final class ComponentScreenFactory
{
    public function __construct(private readonly ElementPublisher $publisher)
    {
    }

    /**
     * @param NativeComponent $root A freshly constructed component — an instance that
     *                              has already been bound to a screen is refused
     */
    public function open(NativeComponent $root): ComponentScreen
    {
        return new ComponentScreen($root, $this->publisher);
    }
}
