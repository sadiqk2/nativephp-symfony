<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\ElementPublisher;

/**
 * One native screen: a root component, its frames, and the interactions that come back.
 *
 * This is the object the native runloop holds. It owns the component graph's lifetime,
 * which is the honest place for it — a screen is exactly the unit that appears, accepts
 * interactions, and goes away, so tying component state to it means state cannot
 * outlive the thing it describes.
 *
 * The frame cycle is reset → build → publish (contract §1); `ElementPublisher` does the
 * transport and keeps the `lastNodeHashes` map that makes subtree reuse work, so an
 * interaction that changes one label republishes one node and marks the rest
 * `flags: 1`.
 */
final class ComponentScreen
{
    private bool $closed = false;

    /**
     * @param NativeComponent  $root      Bound here, and to this screen only
     * @param ElementPublisher $publisher Shared across screens; its diff state is
     *                                    reset below
     */
    public function __construct(
        private readonly NativeComponent $root,
        private readonly ElementPublisher $publisher,
    ) {
        // The root's registry is unscoped, matching upstream's convention that a
        // screen's own callbacks derive ids straight from the expression; children
        // scope themselves relative to it.
        $this->root->bind(new CallbackRegistry());

        // Node ids are only meaningful within one screen's tree. Carrying the previous
        // screen's hashes over would let the renderer splice an unrelated subtree for a
        // colliding id — a wrong-but-plausible screen, which is worse than a blank one.
        $this->publisher->resetDiffState();
    }

    public function root(): NativeComponent
    {
        return $this->root;
    }

    /**
     * Render and publish one frame.
     *
     * @return array<string, mixed> The published tree
     */
    public function frame(): array
    {
        $this->assertOpen();

        $tree = $this->publisher->publish($this->root->renderTree(), $this->root->callbacks());

        // After the publish, not before: expressions are registered while the tree is
        // serialised. The frame this validates has already gone to the device, so this
        // is a development guard against silently dead handlers, not a gate.
        $this->root->assertCallbacksDispatchable();

        return $tree;
    }

    /**
     * Handle one interaction and republish.
     *
     * @param array<string, mixed>|InteractionEvent $event As delivered by the native layer
     *
     * @return array<string, mixed>|null The new frame, or null when the id belongs to
     *                                   no live component — which is routine, not an
     *                                   error: the device can still be showing a
     *                                   superseded frame and tap a node that has since
     *                                   gone away
     *
     * @throws CallbackRefused when a registered id cannot be dispatched to an action
     */
    public function handle(array|InteractionEvent $event): ?array
    {
        $this->assertOpen();

        $interaction = $event instanceof InteractionEvent ? $event : InteractionEvent::fromArray($event);
        $owner = $this->root->ownerOf($interaction->callbackId);

        if (null === $owner) {
            return null;
        }

        $owner->dispatch($interaction);

        return $this->frame();
    }

    /**
     * The callback id a given expression was registered under, for a component in this
     * screen. Mostly for tests and tooling: the device learns ids from the frame, but a
     * test needs to go the other way.
     */
    public function callbackId(string $expression, ?NativeComponent $component = null): ?int
    {
        return ($component ?? $this->root)->callbacks()->idFor($expression);
    }

    /**
     * Tear the screen down: unmount the graph and drop the diff state.
     *
     * Calling this on navigation is what makes `onUnmount()` reliable — a component
     * that acquired something (a subscription, a file handle) has exactly one place
     * where it is told to let go.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->root->unmountTree();
        $this->publisher->resetDiffState();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('This screen has been closed; open a new one with a fresh root component.');
        }
    }
}
