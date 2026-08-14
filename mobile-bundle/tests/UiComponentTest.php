<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\Component\CallbackExpression;
use Native\Symfony\Mobile\Ui\Component\CallbackRefused;
use Native\Symfony\Mobile\Ui\Component\ComponentScreen;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenFactory;
use Native\Symfony\Mobile\Ui\Component\InteractionEvent;
use Native\Symfony\Mobile\Ui\Component\NativeAction;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Elements\Button;
use Native\Symfony\Mobile\Ui\Elements\Column;
use Native\Symfony\Mobile\Ui\Elements\Text;
use Native\Symfony\Mobile\Ui\Elements\TextInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The component lifecycle: state across interactions, callback routing, and the
 * security boundary.
 *
 * The tests assert on published frames rather than on component internals wherever
 * possible, because the frame is the only thing a device sees — a test that passes by
 * reading `$component->count` would not catch a subtree that failed to repaint.
 */
final class UiComponentTest extends TestCase
{
    private ElementPublisher $publisher;

    private function screen(NativeComponent $root): ComponentScreen
    {
        // A fresh publisher per screen, so one test's previous-frame hashes cannot
        // suppress another test's nodes.
        $this->publisher = new ElementPublisher();

        return (new ComponentScreenFactory($this->publisher))->open($root);
    }

    /**
     * A frame with subtree reuse disabled.
     *
     * Needed because a normal frame is a *delta*: an unchanged node comes back as a
     * marker carrying no props, so asserting "the screen still says count:1" against
     * one would fail for the right reason and mislead. Tests that care about what the
     * screen shows use this; tests that care about reuse assert on markers directly.
     *
     * @return array<string, mixed>
     */
    private function fullFrame(ComponentScreen $screen): array
    {
        $this->publisher->resetDiffState();

        return $screen->frame();
    }

    /** @param array<string, mixed> $node */
    private function findNode(array $node, callable $matches): ?array
    {
        if ($matches($node)) {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (null !== ($found = $this->findNode($child, $matches))) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    private function texts(array $node): array
    {
        $texts = [];

        if ('text' === $node['type'] && isset($node['props']['text'])) {
            $texts[] = $node['props']['text'];
        }

        foreach ($node['children'] ?? [] as $child) {
            $texts = [...$texts, ...$this->texts($child)];
        }

        return $texts;
    }

    // ── State and re-render ──────────────────────────

    public function testStateSurvivesARerender(): void
    {
        $screen = $this->screen(new CounterFixture());
        $screen->frame();
        $screen->handle(['callback_id' => $screen->callbackId('increment'), 'type' => InteractionEvent::PRESS]);

        // Re-rendering must not reconstruct the component, or every frame would reset
        // the screen to its initial state — which on a device looks like a flicker
        // rather than an error.
        self::assertContains('count:1', $this->texts($this->fullFrame($screen)));
        self::assertContains('count:1', $this->texts($this->fullFrame($screen)));
    }

    public function testACallbackMutatesStateAndProducesAChangedFrame(): void
    {
        $screen = $this->screen(new CounterFixture());
        $first = $screen->frame();

        $id = $screen->callbackId('increment');
        self::assertIsInt($id);

        $second = $screen->handle(['callback_id' => $id, 'type' => InteractionEvent::PRESS]);
        self::assertNotNull($second);

        self::assertContains('count:0', $this->texts($first));
        self::assertContains('count:1', $this->texts($second));

        $third = $screen->handle(['callback_id' => $id, 'type' => InteractionEvent::PRESS]);
        self::assertNotNull($third);
        self::assertContains('count:2', $this->texts($third));
    }

    public function testTheCallbackIdIsStableAcrossFrames(): void
    {
        // Ids are content-addressed, so a handler keeps its id between frames. If it
        // did not, the native side's bindings for a reused subtree would go stale
        // silently — see the contract §4b.
        $screen = $this->screen(new CounterFixture());
        $screen->frame();
        $before = $screen->callbackId('increment');

        $screen->handle(['callback_id' => $before, 'type' => InteractionEvent::PRESS]);

        self::assertSame($before, $screen->callbackId('increment'));
    }

    public function testAnUnchangedSubtreeIsReusedRatherThanResent(): void
    {
        $screen = $this->screen(new CounterFixture());
        $first = $screen->frame();

        $headerId = $this->findNode($first, static fn (array $n): bool => ($n['props']['text'] ?? null) === 'header')['id'];

        $second = $screen->handle([
            'callback_id' => $screen->callbackId('increment'),
            'type' => InteractionEvent::PRESS,
        ]);

        $header = $this->findNode($second, static fn (array $n): bool => $n['id'] === $headerId);

        self::assertNotNull($header, 'the untouched header node kept its id across frames');
        self::assertSame(1, $header['flags'] ?? null, 'NPHP_NODE_FLAG_REUSE');
        self::assertArrayNotHasKey('props', $header, 'a reused node carries no payload');

        // The node that did change must still be sent in full, or the mutation never
        // reaches the screen.
        $count = $this->findNode($second, static fn (array $n): bool => ($n['props']['text'] ?? null) === 'count:1');
        self::assertNotNull($count);
        self::assertArrayNotHasKey('flags', $count);
    }

    // ── Nested components ────────────────────────────

    public function testAChildComponentResolvesItsOwnCallbacks(): void
    {
        $screen = $this->screen(new ParentFixture());
        $screen->frame();

        $children = $screen->root()->mountedChildren();
        self::assertCount(2, $children);

        [$left, $right] = array_values($children);

        $leftId = $screen->callbackId('bump', $left);
        $rightId = $screen->callbackId('bump', $right);

        // Same expression, different instances: the registry scope is what keeps the
        // ids distinct, and it is the only thing that lets a dispatch tell two
        // sibling children apart.
        self::assertIsInt($leftId);
        self::assertIsInt($rightId);
        self::assertNotSame($leftId, $rightId);

        self::assertNotNull($screen->handle(['callback_id' => $rightId, 'type' => InteractionEvent::PRESS]));

        $texts = $this->texts($this->fullFrame($screen));
        self::assertContains('right taps:1', $texts);
        self::assertContains('left taps:0', $texts, 'the sibling was not dispatched to');
    }

    public function testAChildKeepsItsOwnStateWhileReceivingFreshProps(): void
    {
        $screen = $this->screen(new ParentFixture());
        $screen->frame();

        $right = array_values($screen->root()->mountedChildren())[1];
        $screen->handle(['callback_id' => $screen->callbackId('bump', $right), 'type' => InteractionEvent::PRESS]);

        // A parent-driven re-render refreshes props but must not reset the child's own
        // state, otherwise nothing in a child could hold a value.
        self::assertNotNull($screen->handle([
            'callback_id' => $screen->callbackId('parentTick'),
            'type' => InteractionEvent::PRESS,
        ]));

        $texts = $this->texts($this->fullFrame($screen));
        self::assertContains('right taps:1', $texts);
        self::assertContains('tick:1', $texts);
        self::assertSame($right, array_values($screen->root()->mountedChildren())[1], 'the instance was reused');
    }

    public function testAChildIsMountedOnceAndUnmountedWhenItLeavesTheTree(): void
    {
        LifecycleChildFixture::$mounted = 0;
        LifecycleChildFixture::$unmounted = 0;

        $root = new ToggleableParentFixture();
        $screen = $this->screen($root);

        $screen->frame();
        $screen->frame();
        self::assertSame(1, LifecycleChildFixture::$mounted);
        self::assertSame(0, LifecycleChildFixture::$unmounted);

        $root->showChild = false;
        $screen->frame();

        self::assertSame(1, LifecycleChildFixture::$unmounted, 'left the tree');
        self::assertSame([], $root->mountedChildren());

        $root->showChild = true;
        $screen->frame();
        self::assertSame(2, LifecycleChildFixture::$mounted, 'a returning identity is a fresh mount');
    }

    public function testAKeyedChildKeepsItsStateWhenSiblingsReorder(): void
    {
        $root = new KeyedListFixture();
        $screen = $this->screen($root);
        $screen->frame();

        $b = $screen->root()->mountedChildren()[BadgeFixture::class.'|key:b'] ?? null;
        self::assertNotNull($b);

        $screen->handle(['callback_id' => $screen->callbackId('bump', $b), 'type' => InteractionEvent::PRESS]);

        $root->order = ['b', 'a'];
        $frame = $this->fullFrame($screen);

        // Identity comes from the key, not the position — the whole point of §3.
        self::assertSame($b, $screen->root()->mountedChildren()[BadgeFixture::class.'|key:b']);
        self::assertContains('b taps:1', $this->texts($frame));
        self::assertContains('a taps:0', $this->texts($frame));
    }

    public function testGrandchildCallbacksResolveThroughTwoBoundaries(): void
    {
        $screen = $this->screen(new GrandparentFixture());
        $screen->frame();

        $child = array_values($screen->root()->mountedChildren())[0];
        $grandchild = array_values($child->mountedChildren())[0];

        $frame = $screen->handle([
            'callback_id' => $screen->callbackId('bump', $grandchild),
            'type' => InteractionEvent::PRESS,
        ]);

        self::assertNotNull($frame);
        self::assertContains('deep taps:1', $this->texts($frame));
    }

    public function testAComponentBoundaryEmitsNoNodeOfItsOwn(): void
    {
        // If the boundary emitted a node, every sibling's positional index and every
        // unkeyed sequential id would shift — a device symptom (a list losing its
        // scroll position), never an error.
        $withChild = $this->screen(new BoundaryShapeFixture(true))->frame();
        $withoutChild = $this->screen(new BoundaryShapeFixture(false))->frame();

        self::assertCount(2, $withChild['children']);
        self::assertCount(1, $withoutChild['children']);
        self::assertSame('text', $withChild['children'][1]['type'], 'the child\'s own root, not a wrapper');
    }

    // ── Props ────────────────────────────────────────

    public function testPropsAreAssignedOnEveryRender(): void
    {
        $screen = $this->screen(new ParentFixture());
        $screen->frame();

        $frame = $screen->handle(['callback_id' => $screen->callbackId('parentTick'), 'type' => InteractionEvent::PRESS]);

        self::assertNotNull($frame);
        self::assertContains('left#1 label:left#1', $this->texts($frame));
    }

    public function testAnUnknownPropIsRejected(): void
    {
        // Upstream ignores unmatched attributes because they arrive from HTML. Here the
        // caller wrote a PHP array, so a typo is a child rendering stale data forever.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no prop "lable"/');

        $this->screen(new BadPropFixture())->frame();
    }

    public function testAReadonlyPropIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is readonly and cannot be a prop/');

        $this->screen(new ReadonlyPropFixture())->frame();
    }

    // ── Screen isolation ─────────────────────────────

    public function testAComponentCannotBeBoundToTwoScreens(): void
    {
        $root = new CounterFixture();
        $this->screen($root);

        // The structural guarantee that state cannot leak between unrelated screens:
        // there is no shared registry to clear, only instances that belong to one
        // screen each.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/already bound/');

        $this->screen($root);
    }

    public function testClosingAScreenUnmountsTheGraphAndRefusesFurtherFrames(): void
    {
        $screen = $this->screen(new ToggleableParentFixture());
        $screen->frame();
        LifecycleChildFixture::$unmounted = 0;

        $screen->close();
        self::assertSame(1, LifecycleChildFixture::$unmounted);

        $this->expectException(\LogicException::class);
        $screen->frame();
    }

    public function testASelfMountingComponentIsStoppedRatherThanHanging(): void
    {
        // Unbounded recursion here never publishes a frame at all, so the device shows
        // a frozen screen with nothing in the log.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nesting exceeded/');

        $this->screen(new SelfMountingFixture())->frame();
    }

    // ── The security boundary ────────────────────────

    public function testAnUnknownCallbackIdIsDroppedRatherThanRefused(): void
    {
        $screen = $this->screen(new CounterFixture());
        $screen->frame();

        // Routine, not an attack: the device may still be showing a superseded frame.
        self::assertNull($screen->handle(['callback_id' => 123456, 'type' => InteractionEvent::PRESS]));
        self::assertNull($screen->handle(['callback_id' => 0, 'type' => InteractionEvent::PRESS]));
    }

    public function testAnExpressionNamingAnUnmarkedMethodIsRefusedAtRenderTime(): void
    {
        // The frame is published before this check runs, so what it buys is timing: the
        // alternative is a button that silently does nothing on a phone.
        $this->expectException(CallbackRefused::class);
        $this->expectExceptionMessageMatches('/is not a #\[NativeAction\]/');

        $this->screen(new UnmarkedMethodFixture())->frame();
    }

    public function testAFrameworkMethodCannotBeReachedFromTheDevice(): void
    {
        $screen = $this->screen(new CounterFixture());
        $screen->frame();

        // Simulates the worst case the boundary is designed for: an expression that
        // somehow reached the registry naming a real, public, dangerous method. It is
        // registered exactly as a render would have registered it, so this exercises
        // the dispatch-time allowlist rather than the render-time guard.
        $id = $screen->root()->callbacks()->register('unmountTree');

        $this->expectException(CallbackRefused::class);
        $this->expectExceptionMessageMatches('/unmountTree\(\) is not a #\[NativeAction\]/');

        $screen->handle(['callback_id' => $id, 'type' => InteractionEvent::PRESS]);
    }

    public function testAMagicMethodCannotBeReachedFromTheDevice(): void
    {
        $screen = $this->screen(new CounterFixture());
        $screen->frame();

        $id = $screen->root()->callbacks()->register('__destruct');

        $this->expectException(CallbackRefused::class);
        $this->expectExceptionMessageMatches('/unknown reserved callback/');

        $screen->handle(['callback_id' => $id, 'type' => InteractionEvent::PRESS]);
    }

    public function testAnActionMarkedOnAMagicMethodIsStillNotDispatchable(): void
    {
        $screen = $this->screen(new MagicActionFixture());
        $screen->frame();

        $id = $screen->root()->callbacks()->register('__invoke');

        $this->expectException(CallbackRefused::class);

        $screen->handle(['callback_id' => $id, 'type' => InteractionEvent::PRESS]);
    }

    public function testACaseVariantOfAnActionIsRefused(): void
    {
        // PHP method names are case-insensitive, so matching loosely would give one
        // handler two callback ids and break the id stability reuse depends on.
        $screen = $this->screen(new CounterFixture());
        $screen->frame();

        $id = $screen->root()->callbacks()->register('Increment');

        $this->expectException(CallbackRefused::class);

        $screen->handle(['callback_id' => $id, 'type' => InteractionEvent::PRESS]);
    }

    #[DataProvider('maliciousExpressions')]
    public function testAMaliciousExpressionIsNeverParsedIntoACall(string $expression): void
    {
        $this->expectException(CallbackRefused::class);

        CallbackExpression::parse($expression);
    }

    public static function maliciousExpressions(): iterable
    {
        yield 'a function call as an argument' => ['delete(system("id"))'];
        yield 'a static call' => ['Foo::bar()'];
        yield 'a variable' => ['$handler'];
        yield 'a property path' => ['repo->delete(1)'];
        yield 'chained calls' => ['increment()->flush()'];
        yield 'a nested array argument' => ["tag(['a'])"];
        yield 'an unterminated string' => ["tag('a"];
        yield 'an unclosed argument list' => ['tag(1'];
        yield 'an empty argument' => ['tag(1,,2)'];
        yield 'whitespace in the method name' => ['in crement'];
        yield 'an empty expression' => [''];
        yield 'a spliced string' => ["tag('a'.'b')"];
        yield 'an unsupported escape' => ["tag('a\\nb')"];
    }

    public function testLiteralArgumentsAreParsedFaithfully(): void
    {
        $expression = CallbackExpression::parse("tag('it\\'s', -2, 1.5, true, null)");

        self::assertSame('tag', $expression->method);
        // Upstream swaps quotes and JSON-decodes, which loses the apostrophe and
        // degrades a decode failure to no arguments at all.
        self::assertSame(["it's", -2, 1.5, true, null], $expression->arguments);
    }

    public function testLiteralArgumentsReachTheAction(): void
    {
        $screen = $this->screen(new ArgumentFixture());
        $screen->frame();

        $frame = $screen->handle([
            'callback_id' => $screen->callbackId("select('b', 2)"),
            'type' => InteractionEvent::PRESS,
        ]);

        self::assertNotNull($frame);
        self::assertContains('picked:b/2', $this->texts($frame));
    }

    public function testTooManyLiteralArgumentsAreRefused(): void
    {
        $this->expectException(CallbackRefused::class);
        $this->expectExceptionMessageMatches('/cannot take 3 argument\(s\)/');

        $this->screen(new TooManyArgumentsFixture())->frame();
    }

    public function testAnActionThatCannotBeSatisfiedIsRefusedAtDispatch(): void
    {
        // Not checkable at render time: how many arguments a call ends up with depends
        // on the event type, which only exists once the interaction arrives.
        $screen = $this->screen(new MissingArgumentsFixture());
        $screen->frame();

        $this->expectException(CallbackRefused::class);
        $this->expectExceptionMessageMatches('/requires 2 argument\(s\), 0 available/');

        $screen->handle(['callback_id' => $screen->callbackId('select'), 'type' => InteractionEvent::PRESS]);
    }

    public function testEventPayloadIsCoercedAndAppended(): void
    {
        $screen = $this->screen(new InputFixture());
        $screen->frame();

        $frame = $screen->handle([
            'callback_id' => $screen->callbackId('setText'),
            'type' => InteractionEvent::TEXT_CHANGE,
            'text' => 'hello',
        ]);

        self::assertNotNull($frame);
        self::assertContains('text:hello', $this->texts($frame));
    }

    public function testEventPayloadIsNeverAppendedToAVariadic(): void
    {
        // A variadic says nothing about how many values it expects, so appending
        // device-controlled ones would make the count attacker-influenced.
        $screen = $this->screen(new VariadicFixture());
        $screen->frame();

        $frame = $screen->handle([
            'callback_id' => $screen->callbackId("collect('a')"),
            'type' => InteractionEvent::TEXT_CHANGE,
            'text' => 'injected',
        ]);

        self::assertNotNull($frame);
        self::assertContains('collected:a', $this->texts($frame));
    }

    public function testAToggleEventArrivesAsABool(): void
    {
        $event = InteractionEvent::fromArray([
            'callback_id' => 7,
            'type' => InteractionEvent::TOGGLE_CHANGE,
            'value' => 'false',
        ]);

        // Coerced by type, so a handler declaring bool cannot be handed the string
        // "false" — which is truthy, and would invert the meaning of the switch.
        self::assertSame([true], $event->payload);
        self::assertSame(7, $event->callbackId);
    }

    public function testAnUnknownEventTypeCarriesNoPayload(): void
    {
        $event = InteractionEvent::fromArray(['callback_id' => 1, 'type' => 99, 'value' => 'x', 'text' => 'y']);

        self::assertSame([], $event->payload);
    }

    public function testANavigateCallbackIsAcceptedAndDeliveredToTheHook(): void
    {
        $root = new NavigatingFixture();
        $screen = $this->screen($root);
        $frame = $screen->frame();

        $press = $this->findNode($frame, static fn (array $n): bool => isset($n['on_press']))['on_press'];

        self::assertNull($root->navigatedTo);
        $screen->handle(['callback_id' => $press, 'type' => InteractionEvent::PRESS]);

        // Routing is a separate milestone; what matters here is that a navigating
        // element neither fails validation nor throws on press.
        self::assertNotNull($root->navigatedTo);
        self::assertSame(['screen' => 'detail'], $root->callbacks()->navigation($root->navigatedTo));
    }
}

// ── Fixtures ─────────────────────────────────────────

final class CounterFixture extends NativeComponent
{
    private int $count = 0;

    #[NativeAction]
    public function increment(): void
    {
        ++$this->count;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make('header'),
            Text::make('count:'.$this->count),
            Button::make('+')->onPress('increment'),
        );
    }
}

final class BadgeFixture extends NativeComponent
{
    public string $label = '';

    private int $taps = 0;

    #[NativeAction]
    public function bump(): void
    {
        ++$this->taps;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make($this->label.' taps:'.$this->taps),
            Text::make($this->label.' label:'.$this->label),
            Button::make('bump')->onPress('bump'),
        );
    }
}

final class ParentFixture extends NativeComponent
{
    private int $tick = 0;

    #[NativeAction]
    public function parentTick(): void
    {
        ++$this->tick;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make('tick:'.$this->tick),
            $this->mount(BadgeFixture::class, ['label' => 0 === $this->tick ? 'left' : 'left#'.$this->tick]),
            $this->mount(BadgeFixture::class, ['label' => 'right']),
            Button::make('tick')->onPress('parentTick'),
        );
    }
}

final class LifecycleChildFixture extends NativeComponent
{
    public static int $mounted = 0;

    public static int $unmounted = 0;

    protected function onMount(): void
    {
        ++self::$mounted;
    }

    protected function onUnmount(): void
    {
        ++self::$unmounted;
    }

    protected function render(): Element
    {
        return Text::make('child');
    }
}

final class ToggleableParentFixture extends NativeComponent
{
    public bool $showChild = true;

    protected function render(): Element
    {
        $column = Column::make(Text::make('parent'));

        if ($this->showChild) {
            $column->child($this->mount(LifecycleChildFixture::class));
        }

        return $column;
    }
}

final class KeyedListFixture extends NativeComponent
{
    /** @var list<string> */
    public array $order = ['a', 'b'];

    protected function render(): Element
    {
        $column = Column::make();

        foreach ($this->order as $id) {
            $column->child($this->mount(BadgeFixture::class, ['label' => $id], key: $id));
        }

        return $column;
    }
}

final class DeepChildFixture extends NativeComponent
{
    private int $taps = 0;

    #[NativeAction]
    public function bump(): void
    {
        ++$this->taps;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make('deep taps:'.$this->taps),
            Button::make('bump')->onPress('bump'),
        );
    }
}

final class MiddleFixture extends NativeComponent
{
    protected function render(): Element
    {
        return Column::make(Text::make('middle'), $this->mount(DeepChildFixture::class));
    }
}

final class GrandparentFixture extends NativeComponent
{
    protected function render(): Element
    {
        return Column::make(Text::make('top'), $this->mount(MiddleFixture::class));
    }
}

final class BoundaryShapeFixture extends NativeComponent
{
    public function __construct(private readonly bool $withChild = true)
    {
    }

    protected function render(): Element
    {
        $column = Column::make(Text::make('only'));

        if ($this->withChild) {
            $column->child($this->mount(LeafFixture::class));
        }

        return $column;
    }
}

final class LeafFixture extends NativeComponent
{
    protected function render(): Element
    {
        return Text::make('leaf');
    }
}

final class BadPropFixture extends NativeComponent
{
    protected function render(): Element
    {
        return $this->mount(BadgeFixture::class, ['lable' => 'oops']);
    }
}

final class ReadonlyPropFixture extends NativeComponent
{
    protected function render(): Element
    {
        return $this->mount(ReadonlyChildFixture::class, ['label' => 'x']);
    }
}

final class ReadonlyChildFixture extends NativeComponent
{
    public readonly string $label;

    protected function render(): Element
    {
        return Text::make('readonly');
    }
}

final class SelfMountingFixture extends NativeComponent
{
    protected function render(): Element
    {
        return Column::make(Text::make('again'), $this->mount(self::class));
    }
}

final class UnmarkedMethodFixture extends NativeComponent
{
    /** Public, and looks harmless — but never opted in. */
    public function wipe(): void
    {
    }

    protected function render(): Element
    {
        return Button::make('wipe')->onPress('wipe');
    }
}

final class MagicActionFixture extends NativeComponent
{
    #[NativeAction]
    public function __invoke(): void
    {
    }

    protected function render(): Element
    {
        return Text::make('magic');
    }
}

final class ArgumentFixture extends NativeComponent
{
    private string $picked = '';

    #[NativeAction]
    public function select(string $name, int $index): void
    {
        $this->picked = $name.'/'.$index;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make('picked:'.$this->picked),
            Button::make('b')->onPress("select('b', 2)"),
        );
    }
}

final class TooManyArgumentsFixture extends NativeComponent
{
    #[NativeAction]
    public function select(string $name): void
    {
    }

    protected function render(): Element
    {
        return Button::make('b')->onPress("select('a', 2, 3)");
    }
}

final class MissingArgumentsFixture extends NativeComponent
{
    #[NativeAction]
    public function select(string $name, int $index): void
    {
    }

    protected function render(): Element
    {
        return Button::make('b')->onPress('select');
    }
}

final class InputFixture extends NativeComponent
{
    private string $text = '';

    #[NativeAction]
    public function setText(string $text): void
    {
        $this->text = $text;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make('text:'.$this->text),
            TextInput::make($this->text)->onChange('setText'),
        );
    }
}

final class VariadicFixture extends NativeComponent
{
    private string $collected = '';

    #[NativeAction]
    public function collect(string ...$values): void
    {
        $this->collected = implode(',', $values);
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make('collected:'.$this->collected),
            TextInput::make()->onChange("collect('a')"),
        );
    }
}

final class NavigatingFixture extends NativeComponent
{
    public ?string $navigatedTo = null;

    protected function onNavigate(string $key): void
    {
        $this->navigatedTo = $key;
    }

    protected function render(): Element
    {
        return Button::make('go')->navigate(['screen' => 'detail']);
    }
}
