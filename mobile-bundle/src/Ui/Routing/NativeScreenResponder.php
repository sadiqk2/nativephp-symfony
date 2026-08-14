<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementPublisher;

/**
 * Answers a native-screen request: locate the screen for a path, render it through the
 * {@see ScreenRendererInterface} seam, publish the frame.
 *
 * The three things this adds over calling the registry and the publisher directly are the
 * three that are easy to get wrong and invisible off-device:
 *
 *  1. **A per-screen callback registry, scoped to the pattern.** Ids are derived from the
 *     handler expression, so two screens with a `save` handler would derive the *same* id;
 *     the scope (joined with `\x1F`, contract §4b) keeps them apart.
 *  2. **Diff state is dropped when the screen changes.** `_hash` reuse markers are keyed by
 *     node id, and ids are only meaningful within one screen's tree — carrying them across a
 *     navigation makes the renderer splice an unrelated subtree it still has cached.
 *  3. **Re-render keeps the same registry.** An interaction arrives carrying an id minted by
 *     the previous frame; re-registering handlers in a fresh registry is fine (ids are
 *     content-derived and stable) but the *resolution* of the incoming id has to happen
 *     against a registry that still holds it.
 *
 * What it deliberately does not do is own a loop. Upstream's `NativeRouter::loop()` is the
 * navigation stack, transitions, mount/resume/unmount — all lifecycle, all somebody else's
 * design. This class renders one screen and publishes frames for it.
 */
final class NativeScreenResponder
{
    private ?NativeRouteMatch $current = null;

    private ?CallbackRegistry $callbacks = null;

    public function __construct(
        private readonly NativeRouteRegistry $routes,
        private readonly ScreenRendererInterface $renderer,
        private readonly ElementPublisher $publisher,
    ) {
    }

    /**
     * Would the device boot this path natively? Same question, same answer as `BootPlanner`.
     */
    public function handles(string $path): bool
    {
        return $this->routes->isNativePath($path);
    }

    /**
     * Render and publish the first frame for `$path`.
     *
     * @return array<string, mixed> The published tree, which off-device is the only way to
     *                              observe a frame at all
     *
     * @throws NativeScreenNotFound when no declared screen matches
     */
    public function respond(string $path): array
    {
        $match = $this->routes->resolve($path);

        if (null === $match) {
            throw NativeScreenNotFound::forPath(NativeRouteMatcher::normalizeStartPath($path));
        }

        // Screen change, not merely a new frame: the previous screen's node ids and hashes
        // must not survive into it.
        if (null === $this->current || $this->current->route->pattern !== $match->route->pattern) {
            $this->publisher->resetDiffState();
            $this->callbacks = new CallbackRegistry($match->route->pattern);
        }

        $this->current = $match;

        return $this->publish();
    }

    /**
     * Publish another frame for the screen already being shown — the path an interaction
     * takes after its handler has mutated state.
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException when nothing has been responded to yet
     */
    public function republish(): array
    {
        if (null === $this->current) {
            throw new \LogicException('There is no current native screen to re-publish. Call respond() first.');
        }

        return $this->publish();
    }

    /**
     * Publish a tree built elsewhere against the current screen's callback registry.
     *
     * The escape hatch for a lifecycle that has already built its root element — an error
     * screen, a placeholder painted before a slow mount — and only needs the scoping and
     * diff-state guarantees above.
     *
     * @return array<string, mixed>
     */
    public function publishTree(Element $root): array
    {
        return $this->publisher->publish($root, $this->callbacks ??= new CallbackRegistry());
    }

    public function currentMatch(): ?NativeRouteMatch
    {
        return $this->current;
    }

    /**
     * The registry the last published frame's callback ids live in — what an incoming
     * interaction id has to be resolved against.
     */
    public function callbacks(): ?CallbackRegistry
    {
        return $this->callbacks;
    }

    /** @return array<string, mixed> */
    private function publish(): array
    {
        \assert(null !== $this->current && null !== $this->callbacks);

        return $this->publisher->publish(
            $this->renderer->renderScreen($this->current, $this->callbacks),
            $this->callbacks,
        );
    }
}
