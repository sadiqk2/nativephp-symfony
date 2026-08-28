<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;

/**
 * A stateful piece of native UI: state, a render, and actions the device can invoke.
 *
 * A native screen is not a one-shot render. The device shows a tree, the user taps
 * something, a callback id comes back, a handler mutates state, and a new frame is
 * published. The three things that needs — state that survives between interactions, a
 * way to route a callback to the right instance, and a re-render that reuses unchanged
 * subtrees — are what this class and {@see ComponentScreen} provide.
 *
 * Usage:
 *
 *     final class Counter extends NativeComponent
 *     {
 *         private int $count = 0;
 *
 *         #[NativeAction]
 *         public function increment(): void
 *         {
 *             ++$this->count;
 *         }
 *
 *         protected function render(): Element
 *         {
 *             return Column::make(
 *                 Text::make("Count: {$this->count}"),
 *                 Button::make('+')->onPress('increment'),
 *                 $this->mount(TotalBadge::class, ['count' => $this->count]),
 *             );
 *         }
 *     }
 *
 *     $screen = $factory->open(new Counter());
 *     $frame  = $screen->frame();                              // first paint
 *     $frame  = $screen->handle(['callback_id' => 42, 'type' => 0]);  // tap → repaint
 *
 * ## Where state lives, and why here
 *
 * State is ordinary typed properties on the subclass. The component instance is held
 * by its `ComponentScreen`, which the native runloop keeps for as long as the screen is
 * on screen — the PHP process is long-lived in persistent mode
 * ({@see \Native\Symfony\Mobile\Runtime\MobileRuntime}), so there is nothing to
 * serialise and nothing to rehydrate.
 *
 * Two alternatives were weighed and rejected:
 *
 *  - **A session or a store, keyed by screen.** This is what Livewire must do, because
 *    HTTP throws the object away between requests. Here it would mean every property
 *    has to be serialisable, closures and services could not be held, and each
 *    interaction would pay a serialise/unserialise round trip on the interaction path.
 *    All of that cost exists to solve a problem this runtime does not have.
 *  - **A container service per component.** Tempting for DI, but `MobileRuntime`
 *    resets everything tagged `kernel.reset` after each dispatch, so component state
 *    would vanish at an unpredictable moment — the worst possible failure mode, since
 *    it looks like a random UI reset on a device and never reproduces in a test.
 *    Collaborators are passed in as props instead.
 *
 * Leakage between screens is prevented structurally rather than by clearing: a
 * component instance can be bound to exactly one screen ({@see bind}), and closing a
 * screen unmounts its whole graph. There is no global registry of live components to
 * go stale.
 *
 * ## What is out of scope
 *
 * Navigation (routing between screens) and polling/timers are separate milestones.
 * `__navigate` callbacks are recognised so a navigating element still renders and
 * dispatches, but they are delivered to the {@see onNavigate} hook and this class does
 * nothing else with them.
 *
 * Upstream's `SharedValue` is also *not* part of this: it is a handle to a numeric
 * value that lives on the native side and is mutated on the UI thread by gestures, so
 * its per-frame values never cross into PHP and it has nothing to do with component
 * state or the callback round trip. It belongs with the element and style layers.
 *
 * Also absent, and deliberately: an event bus between components. Upstream bubbles
 * `emit()` up the ancestor chain, which is real machinery with real ordering rules;
 * until something needs it, a child telling a parent something can be a prop holding a
 * callable, and half-building the bus would only make the eventual design harder.
 */
abstract class NativeComponent
{
    /**
     * Depth cap on mounted children.
     *
     * A component that mounts itself, directly or through a cycle, otherwise recurses
     * until the process dies — and on a device that is a frozen screen with no error,
     * because the render never finishes and no frame is ever published.
     */
    private const MAX_DEPTH = 32;

    /**
     * All internal state is private and `native`-prefixed: private so a subclass
     * cannot corrupt it by accident, prefixed so the prefix shows up in a stack trace
     * as framework state rather than app state.
     */
    private ?CallbackRegistry $nativeCallbacks = null;

    private ?self $nativeParent = null;

    private int $nativeDepth = 0;

    private bool $nativeBound = false;

    /** @var array<string, self> Live children, keyed by identity, reused across frames */
    private array $nativeChildren = [];

    /** @var array<string, int> Per-class occurrence counters for the frame being rendered */
    private array $nativeOccurrences = [];

    /** @var array<string, true> Identities mounted during the frame being rendered */
    private array $nativeSeen = [];

    /**
     * Build this component's subtree. Called once per frame, and may mount children
     * via {@see mount()}.
     */
    abstract protected function render(): Element;

    /** Called once, when this component first enters a screen's tree. */
    protected function onMount(): void
    {
    }

    /** Called when this component leaves the tree, or its screen is closed. */
    protected function onUnmount(): void
    {
    }

    /**
     * A navigating element was pressed.
     *
     * Routing is a separate milestone, so the default is a no-op rather than an
     * exception: a screen containing a `navigate` element must not crash just because
     * the navigation half of the port does not exist yet. `$key` is the
     * content-addressed navigation key; the config behind it is on the registry
     * ({@see CallbackRegistry::navigation()}).
     */
    protected function onNavigate(string $key): void
    {
    }

    /**
     * Attach this component to a screen.
     *
     * Binding twice is refused, and that refusal is what keeps state from leaking
     * between screens: reusing a component instance for a second screen would carry
     * over its children, its registry and its state, and the reuse would be invisible
     * until a stale subtree appeared on a device.
     *
     * @internal called by {@see ComponentScreen} and by {@see mount()}
     */
    final public function bind(CallbackRegistry $callbacks, ?self $parent = null, int $depth = 0): void
    {
        if ($this->nativeBound) {
            throw new \LogicException(sprintf(
                '%s is already bound to a component tree. Construct a new instance per screen — '.
                'reusing one would carry its state and children across.',
                static::class,
            ));
        }

        $this->nativeBound = true;
        $this->nativeCallbacks = $callbacks;
        $this->nativeParent = $parent;
        $this->nativeDepth = $depth;
    }

    /**
     * Whether this component has already been attached to a tree.
     *
     * For callers that obtain instances from somewhere they do not control — a service
     * locator, say — and would otherwise learn about it from a `bind()` exception that
     * cannot say where the instance came from.
     */
    final public function isBound(): bool
    {
        return $this->nativeBound;
    }

    /**
     * This component's own registry — the one its elements register into, and the one
     * a returning callback id is looked up in.
     */
    final public function callbacks(): CallbackRegistry
    {
        // An unbound component (constructed in a test, rendered directly) gets an
        // unscoped registry rather than a TypeError. Children are always bound by
        // mount() before they render, so this default cannot produce a scope clash.
        return $this->nativeCallbacks ??= new CallbackRegistry();
    }

    /**
     * Render one frame of this component, reconciling its children afterwards.
     *
     * @internal called by {@see ComponentScreen::frame()} and by {@see mount()}
     */
    final public function renderTree(): Element
    {
        $this->nativeOccurrences = [];
        $this->nativeSeen = [];

        $tree = $this->render();

        // Anything present last frame but not mounted this one has left the tree.
        // Unmounting here rather than lazily is what stops a long-lived screen from
        // holding every row a list has ever shown.
        foreach ($this->nativeChildren as $identity => $child) {
            if (!isset($this->nativeSeen[$identity])) {
                $child->unmountTree();
                unset($this->nativeChildren[$identity]);
            }
        }

        return $tree;
    }

    /**
     * Mount a child component and return its subtree, ready to place in this render.
     *
     * Identity — an explicit `$key`, else the class's occurrence index within this
     * render — is what makes the child's own state survive a re-render: the same
     * identity resolves to the same instance next frame, gets fresh props, and keeps
     * everything else. Give a `$key` to anything in a list that can reorder, for the
     * same reason `Element::key()` exists (contract §3): without one, identity is
     * positional and a reordered list hands row 2's state to row 1.
     *
     * @param class-string<self>   $class Must be constructible with no arguments —
     *                                    constructor injection for children is out of
     *                                    scope; pass collaborators as props
     * @param array<string, mixed> $props Assigned to public properties of the child
     */
    protected function mount(string $class, array $props = [], int|string|null $key = null): Element
    {
        if (!is_subclass_of($class, self::class)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a %s.', $class, self::class));
        }

        if ($this->nativeDepth + 1 >= self::MAX_DEPTH) {
            throw new \RuntimeException(sprintf(
                'Component nesting exceeded %d levels at %s — is a component mounting itself, directly or via a cycle?',
                self::MAX_DEPTH,
                $class,
            ));
        }

        if (null !== $key) {
            $identity = $class.'|key:'.$key;
        } else {
            $index = $this->nativeOccurrences[$class] ?? 0;
            $this->nativeOccurrences[$class] = $index + 1;
            $identity = $class.'|i:'.$index;
        }

        if (isset($this->nativeSeen[$identity])) {
            throw new \LogicException(sprintf(
                'Two children with the same identity "%s" were mounted in one render of %s. '.
                'Duplicate keys make state and callbacks ambiguous.',
                $identity,
                static::class,
            ));
        }

        // Identity carries the class, so this can only ever be an instance of $class. A
        // keyed slot rendering a different class is a different identity: the new one is
        // mounted here and the old one is unmounted by the reconciliation sweep at the end
        // of renderTree(). There used to be a guard here for "same slot, different class",
        // which read as the thing preventing props from landing on the wrong instance —
        // but it could not run, and the comment on it sent a reader looking in the wrong
        // place for behaviour that lives one function up.
        $child = $this->nativeChildren[$identity] ?? null;

        $isNew = null === $child;

        if ($isNew) {
            $child = $this->instantiate($class);

            // Scopes chain, so the same class at the same index under two different
            // parents still derives distinct callback ids (contract §4b: the scope is
            // joined to the expression with \x1F).
            $scope = '' === $this->callbacks()->scope()
                ? $identity
                : $this->callbacks()->scope().'>'.$identity;

            $child->bind(new CallbackRegistry($scope), $this, $this->nativeDepth + 1);
            $this->nativeChildren[$identity] = $child;
        }

        $this->applyProps($child, $props);
        $this->nativeSeen[$identity] = true;

        if ($isNew) {
            $child->onMount();
        }

        // The child's own root element, pinned to the child's registry — not wrapped in
        // one. The caller gets the element it asked for, so a `key()` or a `layout()` on
        // the mounted row lands on the node the renderer sees.
        return $child->renderTree()->ownCallbacks($child->callbacks());
    }

    /** The component this one was mounted by, or null for a screen's root. */
    final public function parent(): ?self
    {
        return $this->nativeParent;
    }

    /**
     * Live children, keyed by identity.
     *
     * @return array<string, self>
     */
    final public function mountedChildren(): array
    {
        return $this->nativeChildren;
    }

    /**
     * The component whose registry knows this callback id: this one, or the first
     * descendant that does.
     *
     * Ids are scoped hashes rather than counters, so two components cannot produce the
     * same id for the same expression; a cross-scope hash collision is possible in
     * principle (~1 in 2^31) and first-match wins, which is the same trade-off
     * upstream makes.
     */
    final public function ownerOf(int $callbackId): ?self
    {
        if (0 !== $callbackId && null !== $this->callbacks()->expression($callbackId)) {
            return $this;
        }

        foreach ($this->nativeChildren as $child) {
            if (null !== ($owner = $child->ownerOf($callbackId))) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * Run the action a callback id names.
     *
     * The id is used for exactly one thing — looking up the expression this component
     * registered. Nothing from the device reaches the method name.
     *
     * @internal callers must resolve the owner with {@see ownerOf()} first
     *
     * @throws CallbackRefused
     */
    final public function dispatch(InteractionEvent $event): void
    {
        $raw = $this->callbacks()->expression($event->callbackId);

        if (null === $raw) {
            throw new CallbackRefused(sprintf(
                'Callback id %d is not registered on %s.',
                $event->callbackId,
                static::class,
            ));
        }

        $expression = CallbackExpression::parse($raw);

        if ($expression->isReserved()) {
            $this->dispatchReserved($expression);

            return;
        }

        $method = ComponentActions::resolve($this, $expression);

        $slots = ComponentActions::eventArgumentSlots($method, \count($expression->arguments));
        $arguments = [...$expression->arguments, ...\array_slice($event->payload, 0, $slots)];

        ComponentActions::assertSatisfies($method, $arguments, $expression->raw);

        // Invoked through the ReflectionMethod that came out of the allowlist, not by
        // re-resolving the name on $this — so there is no second, looser lookup that
        // could disagree with the check above.
        $method->invoke($this, ...$arguments);
    }

    /**
     * Assert every expression this graph has registered can actually be dispatched.
     *
     * Run after a publish, because expressions are registered while the tree is
     * serialised, not while it is built. The point is timing, not security: without
     * it, `->onPress('incremnt')` is a button that does nothing, on a device, with no
     * error anywhere — the single most annoying failure mode this path has. The
     * allowlist check in {@see dispatch()} remains the authoritative one.
     *
     * Argument *counts* are deliberately not checked here. How many values a call ends
     * up with depends on the event type, which is not known until the interaction
     * arrives — so a check at this point would either always pass or reject valid
     * bindings. It belongs at dispatch, and lives there.
     *
     * @throws CallbackRefused
     */
    final public function assertCallbacksDispatchable(): void
    {
        foreach (array_keys($this->callbacks()->expressions()) as $raw) {
            $expression = CallbackExpression::parse((string) $raw);

            if ($expression->isReserved()) {
                $this->assertReservedIsKnown($expression);

                continue;
            }

            ComponentActions::resolve($this, $expression);
        }

        foreach ($this->nativeChildren as $child) {
            $child->assertCallbacksDispatchable();
        }
    }

    /**
     * Unmount this component and everything below it, deepest first.
     *
     * @internal called by {@see ComponentScreen::close()} and by frame reconciliation
     */
    final public function unmountTree(): void
    {
        foreach ($this->nativeChildren as $child) {
            $child->unmountTree();
        }

        $this->nativeChildren = [];
        $this->onUnmount();
    }

    /** @throws CallbackRefused */
    private function dispatchReserved(CallbackExpression $expression): void
    {
        if ('__navigate' !== $expression->method) {
            throw new CallbackRefused(sprintf('Refused callback "%s": unknown reserved callback.', $expression->raw));
        }

        $this->onNavigate((string) ($expression->arguments[0] ?? ''));
    }

    /** @throws CallbackRefused */
    private function assertReservedIsKnown(CallbackExpression $expression): void
    {
        if ('__navigate' !== $expression->method) {
            throw new CallbackRefused(sprintf('Callback "%s" uses the reserved "__" prefix.', $expression->raw));
        }
    }

    /** @param class-string<self> $class */
    private function instantiate(string $class): self
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        if (null !== $constructor && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \InvalidArgumentException(sprintf(
                '%s cannot be mounted: its constructor requires arguments. Pass collaborators as props instead.',
                $class,
            ));
        }

        return new $class();
    }

    /**
     * Assign props to the child's public properties.
     *
     * Unknown or non-assignable props throw, unlike upstream, which ignores them. It
     * can afford to: its props arrive as HTML attributes, where a stray `class` or
     * `wire:x` is normal. Here the caller wrote a PHP array, so an unmatched key is a
     * typo — and a typo'd prop is a child rendering last frame's data forever.
     *
     * No type coercion either, for the same reason: the values are real PHP values,
     * not attribute strings, so a typed property's own declaration is the check.
     *
     * @param array<string, mixed> $props
     */
    private function applyProps(self $child, array $props): void
    {
        if ([] === $props) {
            return;
        }

        $reflection = new \ReflectionClass($child);

        foreach ($props as $name => $value) {
            if (!$reflection->hasProperty($name)) {
                throw new \InvalidArgumentException(sprintf('%s has no prop "%s".', $child::class, $name));
            }

            $property = $reflection->getProperty($name);

            if (!$property->isPublic() || $property->isStatic()) {
                throw new \InvalidArgumentException(sprintf(
                    '%s::$%s is not a prop — props must be public non-static properties.',
                    $child::class,
                    $name,
                ));
            }

            // A readonly prop can only be assigned once, so it would work on the
            // mount frame and throw an Error on the first re-render — a bug that only
            // shows up after the user interacts.
            if ($property->isReadOnly()) {
                throw new \InvalidArgumentException(sprintf(
                    '%s::$%s is readonly and cannot be a prop: props are reassigned on every render.',
                    $child::class,
                    $name,
                ));
            }

            $property->setValue($child, $value);
        }
    }
}
