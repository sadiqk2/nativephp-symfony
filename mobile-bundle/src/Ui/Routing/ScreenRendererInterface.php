<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;

/**
 * The seam between routing and whatever renders a screen.
 *
 * Routing's job ends at "this path is screen X with these parameters". Turning X into an
 * element tree needs a component lifecycle — state, mount, re-render on interaction — and
 * that is a separate, harder design question (NATIVE-UI-CONTRACT.md §7.6). Depending on it
 * from here would mean routing could not be finished, tested, or reviewed until the
 * lifecycle was settled, and would bake today's guess about it into the manifest path.
 *
 * So the contract is deliberately one method with no lifecycle vocabulary in it: given a
 * match and the registry the frame's callbacks must be registered against, return the root
 * element. An implementation may instantiate a component, invoke a controller, or render a
 * Twig template — nothing here needs to know which.
 *
 * The `CallbackRegistry` is passed in rather than created by the renderer because callback
 * ids must be registered against the *same* registry the frame is published with: the node
 * carries an id, the native side sends that id back, and a lookup in a different registry
 * misses silently (see the contract §4b/§5).
 */
interface ScreenRendererInterface
{
    /**
     * @return Element The root node of the frame to publish
     */
    public function renderScreen(NativeRouteMatch $match, CallbackRegistry $callbacks): Element;
}
