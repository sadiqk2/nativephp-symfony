<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui;

/**
 * A node in the native element tree.
 *
 * Implements the wire format in NATIVE-UI-CONTRACT.md — deliberately, rather than
 * incidentally: the renderers on the other side are upstream's Kotlin and Swift, so
 * the output has to match theirs byte for byte, including the content hash. There is
 * a test that asserts exactly that against upstream's own collector.
 *
 * Two consequences of that constraint, which look like odd choices otherwise:
 *
 *  - `_hash` is `xxh3` over a `serialize()` of a fixed-order list. The order and the
 *    types are part of the format, so the array below cannot be tidied.
 *  - Empty `layout`, `style` and `props` are omitted rather than sent as `{}`, which
 *    is what keeps a frame small enough to publish at interactive rates.
 */
abstract class Element
{
    protected string $type = 'element';

    /** @var list<Element> */
    protected array $children = [];

    protected ?int $nodeId = null;

    protected ?string $key = null;

    protected ?string $elementRef = null;

    protected ?string $pressMethod = null;

    protected ?string $longPressMethod = null;

    /** @var array<string, mixed>|null */
    protected ?array $navigateConfig = null;

    /** @var array<string, mixed> */
    protected array $layout = [];

    /** @var array<string, mixed> */
    protected array $style = [];

    /** @var array<string, mixed> */
    protected array $appliedProps = [];

    public function type(): string
    {
        return $this->type;
    }

    /**
     * A stable identity for this node across frames.
     *
     * Without one, a reordering list reuses the wrong nodes — losing scroll position
     * and input focus. See the contract §3.
     */
    public function key(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function ref(string $ref): static
    {
        $this->elementRef = $ref;

        return $this;
    }

    public function onPress(string $expression): static
    {
        $this->pressMethod = $expression;

        return $this;
    }

    public function onLongPress(string $expression): static
    {
        $this->longPressMethod = $expression;

        return $this;
    }

    /** @param array<string, mixed> $config */
    public function navigate(array $config): static
    {
        $this->navigateConfig = $config;

        return $this;
    }

    public function child(self ...$children): static
    {
        foreach ($children as $child) {
            $this->children[] = $child;
        }

        return $this;
    }

    /** @param array<string, mixed> $layout */
    public function layout(array $layout): static
    {
        $this->layout = [...$this->layout, ...$layout];

        return $this;
    }

    /** @param array<string, mixed> $style */
    public function style(array $style): static
    {
        $this->style = [...$this->style, ...$style];

        return $this;
    }

    /**
     * Properties that belong to any element rather than to one kind of element.
     *
     * Upstream sets these in `applyStyle`/`Element::class()` rather than in a
     * per-element `applyAttributes`, and they have no natural home on a typed
     * subclass: `selectable`, `glass`, the four corner radii, and every `dark_*`
     * override apply to whatever they are written on. Without this they were
     * routed through per-element setters, found none, and were silently dropped.
     *
     * @param array<string, mixed> $props
     */
    public function props(array $props): static
    {
        $this->appliedProps = [...$this->appliedProps, ...$props];

        return $this;
    }

    /**
     * Per-element layout defaults, merged *under* anything the author set.
     *
     * The reason this hook exists rather than being folded into a constructor: a
     * default has to be visible in the emitted layout so the renderer applies it,
     * but must lose to an explicit value. Spacer's flex_grow=1 is the canonical
     * case — it works dropped in bare, and can still be overridden.
     *
     * @return array<string, mixed>
     */
    protected function layoutDefaults(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function styleDefaults(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function resolvedLayout(): array
    {
        // array_merge order matters twice over: the author wins, and the resulting
        // key order feeds the content hash.
        return array_merge($this->layoutDefaults(), $this->layout);
    }

    /** @return array<string, mixed> */
    protected function resolvedStyle(): array
    {
        return array_merge($this->styleDefaults(), $this->style);
    }

    /**
     * Element-specific properties. Key order is part of the hash, so subclasses must
     * emit them deterministically.
     *
     * @return array<string, mixed>
     */
    protected function resolvedProps(CallbackRegistry $registry): array
    {
        return [];
    }

    /**
     * Serialise this subtree.
     *
     * @param array<int, bool>    $emittedIds     ids already used this frame
     * @param array<int, string>  $lastNodeHashes hashes from the previous frame;
     *                                            pass and keep the same array across
     *                                            frames to enable subtree reuse
     *
     * @return array<string, mixed>
     */
    public function toArray(
        CallbackRegistry $registry,
        int &$nextId = 1,
        string $parentKeyPath = '',
        int $indexInParent = 0,
        array &$emittedIds = [],
        array &$lastNodeHashes = [],
    ): array {
        [$id, $myKeyPath] = $this->resolveId($parentKeyPath, $indexInParent, $nextId, $emittedIds);

        $layout = $this->resolvedLayout();
        $style = $this->resolvedStyle();
        // Element-specific first, then the ones applied from a stylesheet, so key
        // order stays deterministic — it feeds the content hash.
        $props = [...$this->resolvedProps($registry), ...$this->appliedProps];

        $onPress = null !== $this->pressMethod ? $registry->register($this->pressMethod) : null;
        $onLongPress = null !== $this->longPressMethod ? $registry->register($this->longPressMethod) : null;

        if (null !== $this->navigateConfig) {
            $navKey = $registry->registerNavigation($this->navigateConfig);
            $onPress = $registry->register("__navigate('{$navKey}')");
        }

        // Children first, so their hashes can fold into this one.
        $childNodes = [];
        $childHashes = [];
        $childIndex = 0;

        foreach ($this->children as $child) {
            $childNode = $child->toArray($registry, $nextId, $myKeyPath, $childIndex++, $emittedIds, $lastNodeHashes);
            $childNodes[] = $childNode;
            $childHashes[] = $childNode['_hash'] ?? '';
        }

        // The input list and its order are part of the format. If a mutation ever
        // fails to repaint on the device, the missing field is one that belongs here.
        $contentHash = hash('xxh3', serialize([
            $this->type,
            $layout,
            $style,
            $props,
            $onPress,
            $onLongPress,
            $this->elementRef,
            $childHashes,
        ]));

        $prior = $lastNodeHashes[$id] ?? null;

        if (null !== $prior && $prior === $contentHash) {
            return [
                'id' => $id,
                'type' => $this->type,
                'flags' => 1, // NPHP_NODE_FLAG_REUSE
                '_hash' => $contentHash,
            ];
        }

        $lastNodeHashes[$id] = $contentHash;

        $node = [
            'id' => $id,
            'type' => $this->type,
            '_hash' => $contentHash,
        ];

        if ([] !== $layout) {
            $node['layout'] = $layout;
        }

        if ([] !== $style) {
            $node['style'] = $style;
        }

        if ([] !== $props) {
            $node['props'] = $props;
        }

        if (null !== $onPress) {
            $node['on_press'] = $onPress;
        }

        if (null !== $onLongPress) {
            $node['on_long_press'] = $onLongPress;
        }

        if (null !== $this->elementRef) {
            $node['ref'] = $this->elementRef;
        }

        if ([] !== $childNodes) {
            $node['children'] = $childNodes;
        }

        return $node;
    }

    /**
     * @param array<int, bool> $emittedIds
     *
     * @return array{0: int, 1: string} The id, and this node's key path
     */
    private function resolveId(string $parentKeyPath, int $indexInParent, int &$nextId, array &$emittedIds): array
    {
        if (null !== $this->nodeId) {
            $emittedIds[$this->nodeId] = true;

            $keyPath = '' !== $parentKeyPath || null !== $this->key
                ? $parentKeyPath.'/'.($this->key ?? $indexInParent)
                : '';

            return [$this->nodeId, $keyPath];
        }

        if (null !== $this->key) {
            $keyPath = $parentKeyPath.'/'.$this->key;

            return [self::deriveNodeIdFromKeyPath($keyPath, $emittedIds), $keyPath];
        }

        // Inside a keyed subtree an unkeyed node takes its position within that
        // parent, so unkeyed siblings stay stable while the parent is keyed.
        if ('' !== $parentKeyPath) {
            $keyPath = $parentKeyPath.'/'.$indexInParent;

            return [self::deriveNodeIdFromKeyPath($keyPath, $emittedIds), $keyPath];
        }

        // Skip anything a keyed node already derived. Derived ids are 32-bit hashes and
        // probe on collision, but the sequential counter did not look at the map at all —
        // so a hash that happened to land on a small number was handed out a second time
        // here, and two nodes sharing an id means the renderer collapses them into one
        // piece of native state. Vanishingly unlikely per tree, silent and undebuggable
        // when it happens, and two lines to make impossible.
        while (isset($emittedIds[$nextId])) {
            ++$nextId;
        }

        $id = $nextId++;
        $emittedIds[$id] = true;

        return [$id, ''];
    }

    /**
     * FNV-1a, 32-bit.
     *
     * Not a stylistic choice: the native side computes the same function, so the
     * offset basis, the prime, the 32-bit mask and the 0 → 1 nudge are all part of
     * the format. Verified against upstream's implementation by test.
     *
     * 0 is avoided because the native side treats a 0 callback id as "no callback".
     */
    public static function fnv1a32(string $value): int
    {
        $hash = 0x811C9DC5;
        $length = \strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            $hash ^= \ord($value[$i]);
            // 64-bit PHP keeps the product as an int; mask back to 32-bit unsigned
            // to match the C-side semantics.
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }

        return 0 === $hash ? 1 : $hash;
    }

    /**
     * Node ids use the **full 32 bits**, unlike callback ids which mask to 31.
     * That asymmetry is upstream's and has to be preserved: node ids travel as
     * unsigned, callback ids as a signed Kotlin Int.
     *
     * @param array<int, bool> $emittedIds
     */
    private static function deriveNodeIdFromKeyPath(string $keyPath, array &$emittedIds): int
    {
        $id = self::fnv1a32($keyPath);

        if (!isset($emittedIds[$id])) {
            $emittedIds[$id] = true;

            return $id;
        }

        for ($salt = 1; $salt < 64; ++$salt) {
            $id = self::fnv1a32($keyPath."\x00".$salt);

            if (!isset($emittedIds[$id])) {
                $emittedIds[$id] = true;

                return $id;
            }
        }

        // Unreachable with any real keyset; better than returning a duplicate,
        // which would make two nodes share native state.
        throw new \RuntimeException(sprintf('Could not derive a unique node id for key path "%s".', $keyPath));
    }
}
