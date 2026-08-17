<?php

declare(strict_types=1);

use Native\Symfony\Desktop\Http\BootedController;
use Native\Symfony\Desktop\Http\EventsController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The two endpoints the runtime posts to (contract requirements 5 and 6).
 *
 * Paths are fixed by the runtime — utils.ts::notifyLaravel builds them as
 * /_native/api/{events,booted} and they are not configurable.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('native_desktop_booted', '/_native/api/booted')
        ->controller(BootedController::class)
        ->methods(['POST']);

    $routes->add('native_desktop_events', '/_native/api/events')
        ->controller(EventsController::class)
        ->methods(['POST']);
};
