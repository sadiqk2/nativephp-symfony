<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\Component\ComponentScreen;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenFactory;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Elements\Column;
use Native\Symfony\Mobile\Ui\Elements\Text;
use PHPUnit\Framework\TestCase;

/**
 * What a component tree refuses, and how.
 *
 * The happy paths of the lifecycle are covered by `UiComponentTest`; this is the other
 * half — the guards in `mount()` and the prop assignment, none of which had a test. They
 * are worth having tests precisely because of what they prevent: without them, each of
 * these mistakes produces not an error but a *plausible wrong screen*, on a device, with
 * no log and no way to attach a debugger.
 *
 * A component that mounts itself would recurse until PHP's memory limit; two children
 * sharing an identity would silently swap each other's state and callbacks between
 * renders; a readonly prop would survive the mount frame and throw on the first
 * interaction, which is the worst possible moment for a first failure.
 */
final class UiComponentGuardsTest extends TestCase
{
    private ElementPublisher $publisher;

    public function testMountingSomethingThatIsNotAComponentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a .*NativeComponent/');

        $this->screen(new MountsAnything(\stdClass::class))->frame();
    }

    public function testAComponentThatMountsItselfIsStoppedWithSomethingReadable(): void
    {
        // Without the depth guard this is a memory-limit fatal inside a render, which on
        // a device is the app disappearing with nothing in logcat that names the cause.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mounting itself, directly or via a cycle/');

        $this->screen(new SelfMounting())->frame();
    }

    public function testTwoChildrenWithTheSameKeyAreRefused(): void
    {
        // Identity is what state and callbacks hang off. Two children claiming one
        // identity would take turns owning both, so the screen would work and then
        // quietly stop working after an interaction.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate keys make state and callbacks ambiguous/');

        $this->screen(new DuplicateKeys())->frame();
    }

    public function testTheSameClassMountedTwiceWithoutAKeyIsFine(): void
    {
        // The counterpart to the test above, and the ordinary case: no key means the
        // occurrence index supplies the identity, so a list of identical children works.
        $frame = $this->fullFrame($this->screen(new TwoUnkeyedChildren()));

        self::assertStringContainsString('child', json_encode($frame, \JSON_THROW_ON_ERROR));
    }

    public function testAKeyedSlotRenderingADifferentClassRemountsRatherThanRepropping(): void
    {
        // Assigning the new class's props to the old instance would be the subtle version
        // of this bug: same key, wrong object, and the props land on properties that
        // happen to share a name.
        $root = new SwitchesChildClass();
        $screen = $this->screen($root);

        self::assertSame('child', $this->leafText($this->fullFrame($screen)));

        $root->useSecond = true;

        // The slot keeps its key and changes class: a fresh instance, not the old one
        // wearing the new class's props.
        self::assertSame('second', $this->leafText($this->fullFrame($screen)));
    }

    public function testTheReplacedChildIsUnmountedRatherThanLeftBehind(): void
    {
        // How that actually works is worth pinning, because it is not the branch that
        // looks like it does the work. Identity includes the class name, so a keyed slot
        // rendering a different class is simply a *different* identity — and the old one
        // is cleaned up by the reconciliation sweep at the end of the render, which is
        // also what stops a long-lived list from holding every row it has ever shown.
        Leaf::$unmounted = 0;

        $root = new SwitchesChildClass();
        $screen = $this->screen($root);
        $this->fullFrame($screen);

        self::assertSame(0, Leaf::$unmounted);

        $root->useSecond = true;
        $this->fullFrame($screen);

        self::assertSame(1, Leaf::$unmounted, 'The replaced child kept its state, its callbacks and its place in memory.');
    }

    // ── props ───────────────────────────────────────────────────────────────

    public function testAPropThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no prop "nope"/');

        $this->screen(new PassesProps(['nope' => 1]))->frame();
    }

    public function testAPrivatePropertyIsNotAProp(): void
    {
        // Otherwise a parent could reach into a child's internals by naming them, and the
        // child's encapsulation would depend on nobody guessing a property name.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a prop/');

        $this->screen(new PassesProps(['hidden' => 1]))->frame();
    }

    public function testAReadonlyPropIsRefusedAtMountRatherThanOnTheFirstRerender(): void
    {
        // A readonly property assigns fine once. The failure would therefore arrive on the
        // first interaction — long after the code that caused it, and in front of a user.
        $this->expectException(\InvalidArgumentException::class);

        $this->screen(new PassesProps(['locked' => 1]))->frame();
    }

    public function testAComponentNeedingConstructorArgumentsSaysToUsePropsInstead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Pass collaborators as props instead/');

        $this->screen(new MountsAnything(NeedsArguments::class))->frame();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function screen(NativeComponent $root): ComponentScreen
    {
        $this->publisher = new ElementPublisher();

        return (new ComponentScreenFactory($this->publisher))->open($root);
    }

    /**
     * The text of the frame's single leaf.
     *
     * Read out of the structure rather than matched in the JSON: every frame contains the
     * word "children", so a substring search for "child" finds one in every frame ever
     * published — including the ones that prove the opposite of what is being asserted.
     *
     * @param array<string, mixed> $frame
     */
    private function leafText(array $frame): string
    {
        return (string) ($frame['children'][0]['props']['text'] ?? '');
    }

    /** @return array<string, mixed> */
    private function fullFrame(ComponentScreen $screen): array
    {
        $this->publisher->resetDiffState();

        return $screen->frame();
    }
}

/** Mounts whatever class it is handed, so the guards can be aimed at one at a time. */
final class MountsAnything extends NativeComponent
{
    public function __construct(private readonly string $class)
    {
    }

    protected function render(): Element
    {
        return Column::make($this->mount($this->class));
    }
}

final class SelfMounting extends NativeComponent
{
    protected function render(): Element
    {
        return Column::make($this->mount(self::class));
    }
}

final class DuplicateKeys extends NativeComponent
{
    protected function render(): Element
    {
        return Column::make(
            $this->mount(Leaf::class, key: 'same'),
            $this->mount(Leaf::class, key: 'same'),
        );
    }
}

final class TwoUnkeyedChildren extends NativeComponent
{
    protected function render(): Element
    {
        return Column::make(
            $this->mount(Leaf::class),
            $this->mount(Leaf::class),
        );
    }
}

final class SwitchesChildClass extends NativeComponent
{
    public bool $useSecond = false;

    protected function render(): Element
    {
        return Column::make(
            $this->mount($this->useSecond ? SecondLeaf::class : Leaf::class, key: 'slot'),
        );
    }
}

final class PassesProps extends NativeComponent
{
    /** @param array<string, mixed> $props */
    public function __construct(private readonly array $props)
    {
    }

    protected function render(): Element
    {
        return Column::make($this->mount(PropProbe::class, $this->props));
    }
}

final class PropProbe extends NativeComponent
{
    public string $label = 'probe';

    public readonly int $locked;

    private int $hidden = 0;

    protected function render(): Element
    {
        return Text::make($this->label.$this->hidden);
    }
}

final class NeedsArguments extends NativeComponent
{
    public function __construct(public string $required)
    {
    }

    protected function render(): Element
    {
        return Text::make($this->required);
    }
}

final class Leaf extends NativeComponent
{
    public static int $unmounted = 0;

    protected function onUnmount(): void
    {
        ++self::$unmounted;
    }

    protected function render(): Element
    {
        return Text::make('child');
    }
}

final class SecondLeaf extends NativeComponent
{
    protected function render(): Element
    {
        return Text::make('second');
    }
}
