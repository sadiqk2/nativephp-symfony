<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenRenderer;
use Native\Symfony\Mobile\Ui\Component\InteractionEvent;
use Native\Symfony\Mobile\Ui\Component\NativeAction;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Elements\Button;
use Native\Symfony\Mobile\Ui\Elements\Column;
use Native\Symfony\Mobile\Ui\Elements\Text;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenResponder;
use Native\Symfony\Mobile\Ui\Routing\ScreenRendererInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The join between routing and the component lifecycle.
 *
 * Every one of these failure modes is silent on a device, which is why they are pinned
 * here rather than left to be noticed on hardware: a component re-instantiated per frame
 * gives a screen whose state resets on every tap, and a component bound to a registry of
 * its own gives a screen whose buttons do nothing at all. Neither logs anything.
 */
final class UiComponentScreenRendererTest extends TestCase
{
    private ElementPublisher $publisher;

    private function responder(
        ComponentScreenRenderer $renderer,
        NativeRouteRegistry $routes,
    ): NativeScreenResponder {
        return new NativeScreenResponder($routes, $renderer, $this->publisher = new ElementPublisher());
    }

    /**
     * Republish with subtree reuse disabled.
     *
     * A normal frame is a delta: an unchanged node returns as a marker carrying no props,
     * so asserting on what the screen *says* against one would fail for the wrong reason.
     *
     * @return array<string, mixed>
     */
    private function fullFrame(NativeScreenResponder $responder): array
    {
        $this->publisher->resetDiffState();

        return $responder->republish();
    }

    private function routes(string $pattern, string $screen): NativeRouteRegistry
    {
        $routes = new NativeRouteRegistry();
        $routes->register($pattern, $screen);

        return $routes;
    }

    /** @param array<string, mixed> $node */
    private function texts(array $node): array
    {
        $found = [];

        if ('text' === ($node['type'] ?? null) && isset($node['props']['text'])) {
            $found[] = $node['props']['text'];
        }

        foreach ($node['children'] ?? [] as $child) {
            $found = [...$found, ...$this->texts($child)];
        }

        return $found;
    }

    // ── The seam is filled at all ───────────────────

    public function testItIsTheScreenRenderer(): void
    {
        self::assertInstanceOf(ScreenRendererInterface::class, new ComponentScreenRenderer());
    }

    public function testItRendersARoutedComponentScreen(): void
    {
        $routes = $this->routes('/counter', CounterScreen::class);
        $responder = $this->responder(new ComponentScreenRenderer(), $routes);

        $frame = $responder->respond('/counter');

        self::assertContains('count:0', $this->texts($frame));
    }

    public function testNavigatingToADifferentParameterRendersTheNewOne(): void
    {
        // The pattern is not the identity of a visit: /user/{id} is one pattern and
        // every user is a different screen. Reusing on the pattern alone meant the
        // component was never told about id 2, so the tree came out byte-identical,
        // the diff recognised it as unchanged, and the device kept showing user 1
        // forever with nothing logged anywhere.
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/user/{id}', UserScreen::class));

        $responder->respond('/user/1');
        self::assertContains('user 1', $this->texts($this->fullFrame($responder)));

        $responder->respond('/user/2');
        self::assertContains('user 2', $this->texts($this->fullFrame($responder)));
    }

    public function testATapOnAChildComponentReachesTheChild(): void
    {
        // A callback id belongs to whichever component registered it, which for
        // anything inside a child is not the root. Dispatching on the root refused
        // the id outright, so every tap on a child of a routed screen threw instead
        // of running — and the id is in the published tree, so the device sends it.
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/s', ScreenWithChild::class));

        $frame = $responder->respond('/s');
        $callbackId = $this->firstPressId($frame);

        self::assertNotNull($callbackId, 'The child button must publish a callback id.');
        self::assertTrue($renderer->dispatch('/s', InteractionEvent::press($callbackId)));

        $children = array_values($renderer->mounted('/s')?->mountedChildren() ?? []);
        $child = $children[0] ?? null;
        self::assertInstanceOf(ChildCounter::class, $child);
        self::assertSame(1, $child->n);
    }

    public function testAnUnknownCallbackIdIsRefusedRatherThanThrowing(): void
    {
        // Routine, not exceptional: a device can tap a frame from a screen that has
        // since been replaced.
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/s', ScreenWithChild::class));
        $responder->respond('/s');

        self::assertFalse($renderer->dispatch('/s', InteractionEvent::press(123456789)));
    }

    /** @param array<string, mixed> $node */
    private function firstPressId(array $node): ?int
    {
        if (isset($node['on_press']) && \is_int($node['on_press'])) {
            return $node['on_press'];
        }

        foreach ($node['children'] ?? [] as $child) {
            if (null !== $found = $this->firstPressId($child)) {
                return $found;
            }
        }

        return null;
    }

    // ── State survives a re-render ──────────────────

    public function testTheComponentInstanceSurvivesAcrossFrames(): void
    {
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/counter', CounterScreen::class));

        $responder->respond('/counter');
        $first = $renderer->mounted('/counter');

        $responder->republish();

        // Identity, not equality: a fresh instance with an equal count would pass an
        // equality assertion and still be the bug — state would reset on the next tap.
        self::assertSame($first, $renderer->mounted('/counter'));
    }

    public function testAnInteractionAdvancesStateAndTheNextFrameShowsIt(): void
    {
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/counter', CounterScreen::class));

        $responder->respond('/counter');
        $callbacks = $responder->callbacks();
        self::assertNotNull($callbacks);

        // The id the frame published is the id the device sends back. Resolving it against
        // this registry is the whole point of the responder handing its registry to the
        // renderer instead of letting the renderer make one.
        $id = $callbacks->idFor('increment');
        self::assertNotNull($id);
        self::assertTrue($renderer->dispatch('/counter', InteractionEvent::press($id)));

        self::assertContains('count:1', $this->texts($this->fullFrame($responder)));
    }

    public function testAnUnknownPatternIsRoutineRatherThanFatal(): void
    {
        $renderer = new ComponentScreenRenderer();

        // A device can tap a frame belonging to a screen that has since been replaced.
        self::assertFalse($renderer->dispatch('/gone', InteractionEvent::press(1)));
    }

    // ── A new visit is not a re-render ──────────────

    public function testRevisitingAScreenStartsFromFreshState(): void
    {
        $renderer = new ComponentScreenRenderer();
        $routes = new NativeRouteRegistry();
        $routes->register('/counter', CounterScreen::class);
        $routes->register('/other', OtherScreen::class);
        $responder = $this->responder($renderer, $routes);

        $responder->respond('/counter');
        $id = $responder->callbacks()?->idFor('increment');
        self::assertNotNull($id);
        $renderer->dispatch('/counter', InteractionEvent::press($id));
        $first = $renderer->mounted('/counter');

        // Navigating away and back: the responder mints a new registry, so the renderer
        // must treat this as a new visit rather than as another frame of the same screen.
        $responder->respond('/other');
        $frame = $responder->respond('/counter');

        self::assertNotSame($first, $renderer->mounted('/counter'));
        self::assertContains('count:0', $this->texts($frame));
    }

    public function testForgettingAScreenUnmountsIt(): void
    {
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/counter', CounterScreen::class));

        $responder->respond('/counter');
        $component = $renderer->mounted('/counter');
        self::assertInstanceOf(CounterScreen::class, $component);

        $renderer->forget('/counter');

        self::assertNull($renderer->mounted('/counter'));
        self::assertTrue($component->unmounted, 'onUnmount must run, or a screen leaks for the life of the app');
    }

    public function testForgetAllClearsEveryScreen(): void
    {
        $renderer = new ComponentScreenRenderer();
        $routes = new NativeRouteRegistry();
        $routes->register('/counter', CounterScreen::class);
        $routes->register('/other', OtherScreen::class);
        $responder = $this->responder($renderer, $routes);

        $responder->respond('/counter');
        $responder->respond('/other');
        $renderer->forgetAll();

        self::assertNull($renderer->mounted('/counter'));
        self::assertNull($renderer->mounted('/other'));
    }

    // ── Screens with dependencies ───────────────────

    public function testAScreenRegisteredAsAServiceComesFromTheLocator(): void
    {
        $configured = new GreetingScreen('from the container');
        $renderer = new ComponentScreenRenderer($this->locator([GreetingScreen::class => $configured]));
        $responder = $this->responder($renderer, $this->routes('/greet', GreetingScreen::class));

        $frame = $responder->respond('/greet');

        self::assertContains('from the container', $this->texts($frame));
        self::assertSame($configured, $renderer->mounted('/greet'));
    }

    public function testAScreenAbsentFromTheLocatorIsInstantiatedDirectly(): void
    {
        $renderer = new ComponentScreenRenderer($this->locator([]));
        $responder = $this->responder($renderer, $this->routes('/counter', CounterScreen::class));

        self::assertContains('count:0', $this->texts($responder->respond('/counter')));
    }

    public function testRouteParametersReachAScreenThatWantsThem(): void
    {
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/posts/{slug}', ParameterisedScreen::class));

        $frame = $responder->respond('/posts/hello-world');

        self::assertContains('slug:hello-world', $this->texts($frame));
    }

    // ── Refusals are actionable ─────────────────────

    public function testANonComponentScreenSaysWhatToDoInstead(): void
    {
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/plain', \stdClass::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must extend .*NativeComponent/');

        $responder->respond('/plain');
    }

    public function testAMissingScreenClassIsNamed(): void
    {
        $renderer = new ComponentScreenRenderer();
        $responder = $this->responder($renderer, $this->routes('/ghost', 'App\\Screen\\NoSuchScreen'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('App\\Screen\\NoSuchScreen');

        $responder->respond('/ghost');
    }

    public function testALocatorServiceOfTheWrongTypeIsRefused(): void
    {
        $renderer = new ComponentScreenRenderer($this->locator([CounterScreen::class => new \stdClass()]));
        $responder = $this->responder($renderer, $this->routes('/counter', CounterScreen::class));

        $this->expectException(\LogicException::class);

        $responder->respond('/counter');
    }

    public function testAScreenFromTheLocatorIsMountedAfreshEachTime(): void
    {
        // The container registers screens non-shared, so the locator is expected to hand
        // back a new instance per get() — which is what makes a second mount possible at
        // all, since a component may be bound to one tree only.
        $made = 0;
        $renderer = new ComponentScreenRenderer($this->factoryLocator([
            UserScreen::class => function () use (&$made): UserScreen {
                ++$made;

                return new UserScreen();
            },
        ]));
        $responder = $this->responder($renderer, $this->routes('/user/{id}', UserScreen::class));

        $responder->respond('/user/1');
        self::assertContains('user 1', $this->texts($this->fullFrame($responder)));

        $responder->respond('/user/2');
        self::assertContains('user 2', $this->texts($this->fullFrame($responder)));
        self::assertSame(2, $made, 'Each mount must construct its own screen.');
    }

    public function testASharedScreenServiceIsReportedRatherThanFailingInsideBind(): void
    {
        // Proven against the real classes before this test existed: a shared service comes
        // back already bound on the second mount, and bind() threw a message that could not
        // say where the instance came from — on a device, a 500 on the frame request, so a
        // screen that works once and is blank ever after. Reached by a back-navigation, or
        // on a parameterised route by nothing more than moving to the next id.
        $renderer = new ComponentScreenRenderer($this->locator([UserScreen::class => new UserScreen()]));
        $responder = $this->responder($renderer, $this->routes('/user/{id}', UserScreen::class));

        $responder->respond('/user/1');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/is shared.*shared: false/s');

        $responder->respond('/user/2');
    }

    /**
     * A locator whose services are built per get(), as a non-shared definition behaves.
     *
     * @param array<string, callable(): object> $factories
     */
    private function factoryLocator(array $factories): ContainerInterface
    {
        return new class($factories) implements ContainerInterface {
            /** @param array<string, callable(): object> $factories */
            public function __construct(private readonly array $factories)
            {
            }

            public function has(string $id): bool
            {
                return isset($this->factories[$id]);
            }

            public function get(string $id): object
            {
                return ($this->factories[$id])();
            }
        };
    }

    /** @param array<string, object> $services */
    private function locator(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }

            public function get(string $id): object
            {
                return $this->services[$id];
            }
        };
    }
}

final class CounterScreen extends NativeComponent
{
    public int $count = 0;

    public bool $unmounted = false;

    #[NativeAction]
    public function increment(): void
    {
        ++$this->count;
    }

    protected function onUnmount(): void
    {
        $this->unmounted = true;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make('count:'.$this->count),
            Button::make('+')->onPress('increment'),
        );
    }
}

final class OtherScreen extends NativeComponent
{
    protected function render(): Element
    {
        return Text::make('other');
    }
}

final class GreetingScreen extends NativeComponent
{
    public function __construct(private readonly string $greeting)
    {
    }

    protected function render(): Element
    {
        return Text::make($this->greeting);
    }
}

final class ParameterisedScreen extends NativeComponent
{
    /** @var array<string, string> */
    private array $parameters = [];

    /** @param array<string, string> $parameters */
    public function withRouteParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    protected function render(): Element
    {
        return Text::make('slug:'.($this->parameters['slug'] ?? ''));
    }
}

final class UserScreen extends NativeComponent
{
    public string $id = '';

    /** @param array<string, mixed> $parameters */
    public function withRouteParameters(array $parameters): void
    {
        $this->id = (string) ($parameters['id'] ?? '');
    }

    protected function render(): Element
    {
        return Text::make('user '.$this->id);
    }
}

final class ChildCounter extends NativeComponent
{
    public int $n = 0;

    #[NativeAction]
    public function bump(): void
    {
        ++$this->n;
    }

    protected function render(): Element
    {
        return Button::make('bump')->onPress('bump');
    }
}

final class ScreenWithChild extends NativeComponent
{
    protected function render(): Element
    {
        return Column::make($this->mount(ChildCounter::class));
    }
}
