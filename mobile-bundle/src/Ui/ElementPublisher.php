<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui;

/**
 * Publishes a frame to the native renderers.
 *
 * The element tree has its own extension functions, separate from
 * `nativephp_call()`: init once, then reset → build → publish per frame. See
 * NATIVE-UI-CONTRACT.md §1.
 *
 * Keeps the `lastNodeHashes` map between frames, which is what enables subtree
 * reuse — upstream leaves that to the caller, and without it every frame is a full
 * repaint.
 */
class ElementPublisher
{
    /** @var array<int, string> */
    private array $lastNodeHashes = [];

    private bool $initialised = false;

    /** @var list<array<string, mixed>> Frames captured when the extension is absent */
    private array $captured = [];

    public function isAvailable(): bool
    {
        return \function_exists('nativephp_element_publish');
    }

    public function init(): void
    {
        if ($this->initialised) {
            return;
        }

        if ($this->isAvailable() && \function_exists('nativephp_element_init')) {
            nativephp_element_init();
        }

        $this->initialised = true;
    }

    /**
     * Build and publish one frame.
     *
     * @return array<string, mixed> The published tree, so a caller can inspect or
     *                              test it without a device
     */
    public function publish(Element $root, CallbackRegistry $registry): array
    {
        $this->init();

        if ($this->isAvailable() && \function_exists('nativephp_element_reset')) {
            nativephp_element_reset();
        }

        $nextId = 1;
        $emittedIds = [];

        $tree = $root->toArray($registry, $nextId, '', 0, $emittedIds, $this->lastNodeHashes);

        if ($this->isAvailable()) {
            nativephp_element_publish($tree);
        } else {
            // Off a device there is nothing to publish to. Capturing rather than
            // discarding is what makes a native-UI screen testable at all.
            $this->captured[] = $tree;
        }

        return $tree;
    }

    /**
     * Forget previous-frame hashes, so the next publish is a full repaint.
     *
     * Needed when navigating to a different screen: node ids are only meaningful
     * within one screen's tree, and carrying hashes across would let an unrelated
     * subtree be reused.
     */
    public function resetDiffState(): void
    {
        $this->lastNodeHashes = [];
    }

    public function shutdown(): void
    {
        if ($this->isAvailable() && \function_exists('nativephp_element_shutdown')) {
            nativephp_element_shutdown();
        }

        $this->initialised = false;
        $this->lastNodeHashes = [];
    }

    /** @return list<array<string, mixed>> */
    public function capturedFrames(): array
    {
        return $this->captured;
    }

    /** @return array<string, mixed>|null */
    public function lastFrame(): ?array
    {
        return $this->captured[\count($this->captured) - 1] ?? null;
    }
}
