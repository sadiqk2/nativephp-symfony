<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

/**
 * Marks a component method as reachable from the device.
 *
 * This attribute is the whole allowlist. A callback arriving from the native side
 * is an integer that resolves to an expression the *app itself* authored, but the
 * expression still names a method by string — so without an allowlist, a typo or a
 * crafted registry entry could reach `unmountTree()`, `__destruct()`, a repository
 * method, or anything else public on the component. Default-deny means the set of
 * device-reachable methods is exactly the set a developer opted in to, and it is
 * greppable.
 *
 * Rejected alternative: treating every public method declared on the subclass as an
 * action (Livewire's model). It makes the security surface a property of where a
 * method happens to be declared, so adding an innocent public helper silently widens
 * it — and on a device that widening is invisible.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class NativeAction
{
}
