<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Screens must not be shared services.
 *
 * A `NativeComponent` may be bound to one component tree and one only: `bind()` throws on a
 * second attempt, deliberately, because reusing an instance would carry the previous
 * screen's state and children into the next one. `ComponentScreenRenderer` therefore
 * instantiates a screen afresh whenever it mounts — and when the screen is a service it
 * asks the locator, which for a shared service hands back the very instance it just
 * unmounted. Every second mount of a screen then died on "already bound": a
 * back-navigation, or on a parameterised route merely moving from `/user/1` to `/user/2`.
 *
 * On a device that is a 500 on the frame request, which shows as a screen that works once
 * and is blank ever after.
 *
 * Autoconfiguration already marks screens non-shared, but it only reaches services that
 * opted into it; this covers the ones tagged by hand. An explicit `shared: true` is left
 * alone — the tag is not a licence to overrule what an application asked for in writing,
 * and `ComponentScreenRenderer` reports that case with an actionable message instead.
 */
final class NativeScreenSharingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Deliberately not findTaggedServiceIds(..., true): that throws on an abstract
        // tagged definition, and failing a whole container build over a template service
        // would be this pass inventing a rule nobody asked for. An abstract definition has
        // no instances to share, so there is nothing here to fix.
        foreach ($container->findTaggedServiceIds('native.screen') as $id => $tags) {
            $definition = $container->getDefinition($id);

            if ($definition->isAbstract()) {
                continue;
            }

            // getChanges() distinguishes "left at the default" from "asked for in the
            // application's own configuration"; only the former is ours to change.
            if (!isset($definition->getChanges()['shared'])) {
                $definition->setShared(false);
            }
        }
    }
}
