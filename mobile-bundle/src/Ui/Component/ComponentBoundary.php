<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;

/**
 * A transparent wrapper that switches which registry a child component's subtree
 * registers its callbacks into.
 *
 * Why this exists: each component owns a `CallbackRegistry` scoped to its identity, so
 * two sibling children both offering `remove` get distinct ids and a dispatch can tell
 * which instance owns the tap. But `Element::toArray()` takes the registry from its
 * *caller* and passes the same one down the whole tree, so a child's elements would
 * otherwise register into the screen's registry and the tap would dispatch to the
 * screen.
 *
 * Upstream solves this inside the element itself — `Element::ownCallbacks()` pins a
 * registry that `toArray()` prefers over the caller's. This bundle's `Element` has no
 * such pin (see the report), so the swap is done from outside instead: the boundary
 * is an `Element` for typing purposes only and emits **no node of its own** — it
 * forwards `toArray()` straight to the wrapped subtree with the child's registry
 * substituted, passing through the id counter, key path, position and hash map
 * untouched.
 *
 * That last part is what makes it safe. If the boundary emitted a node, it would
 * shift every sibling's positional index and every unkeyed node's sequential id, which
 * on a device is a list quietly losing its scroll position rather than an error.
 *
 * @internal produced by {@see NativeComponent::mount()}; not part of the app-facing API
 */
final class ComponentBoundary extends Element
{
    private function __construct(
        private readonly Element $inner,
        private readonly CallbackRegistry $registry,
    ) {
    }

    public static function around(Element $inner, CallbackRegistry $registry): self
    {
        return new self($inner, $registry);
    }

    /**
     * The wrapped subtree's type — the boundary has none of its own, and reporting
     * `element` here would mislead anything introspecting a tree.
     */
    public function type(): string
    {
        return $this->inner->type();
    }

    public function toArray(
        CallbackRegistry $registry,
        int &$nextId = 1,
        string $parentKeyPath = '',
        int $indexInParent = 0,
        array &$emittedIds = [],
        array &$lastNodeHashes = [],
    ): array {
        // $registry — the parent's — is deliberately discarded. Everything else is
        // forwarded by reference so the frame's id allocation and reuse bookkeeping
        // behave exactly as if the child's root element sat here directly.
        return $this->inner->toArray(
            $this->registry,
            $nextId,
            $parentKeyPath,
            $indexInParent,
            $emittedIds,
            $lastNodeHashes,
        );
    }
}
