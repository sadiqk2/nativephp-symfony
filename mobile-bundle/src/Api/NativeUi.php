<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * The one bridge method belonging to the native-UI render path rather than the
 * WebView one.
 *
 * Included for completeness of the 54-method surface, but it has **no effect in a
 * Symfony app today**: transitions animate between natively-rendered screens, and
 * a Symfony app takes the WebView path (see MOBILE-ANALYSIS.md §1), where
 * navigation is ordinary page navigation. It becomes meaningful only if the Twig
 * native-UI front end of M6 is built.
 *
 * Kept rather than dropped so the wrapper surface matches the bridge exactly — a
 * missing method looks like an oversight, a documented no-op does not.
 */
final class NativeUi
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /**
     * @param string $transition fade, slide_from_right, slide_from_left,
     *                           slide_from_bottom, fade_from_bottom,
     *                           scale_from_center, parallax_push, none
     */
    public function setTransition(string $transition): bool
    {
        return $this->bridge->dispatch('NativeUI.Transition.Set', ['transition' => $transition]);
    }
}
