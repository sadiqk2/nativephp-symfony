<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\NativeMobileBundle;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenRenderer;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Elements\Text;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Screens must reach the renderer's locator as non-shared services.
 *
 * Not a preference: a `NativeComponent` may be bound to one tree only, so the renderer
 * constructs a screen afresh on every mount, and a shared service hands back the instance
 * it just unmounted. The second mount of any screen then died — a back-navigation, or on a
 * parameterised route merely `/user/1` to `/user/2`. Under the stock `App\:` glob every
 * screen in an application is a service, so it was the ordinary path that broke.
 *
 * Pinned at the container level as well as the renderer because this is the layer that can
 * actually prevent it; the renderer can only report it after the fact.
 */
final class UiScreenSharingTest extends TestCase
{
    public function testAnAutoconfiguredScreenIsNotShared(): void
    {
        $container = $this->container();
        $container->setDefinition('app.screen', (new Definition(SharingProbeScreen::class))->setAutoconfigured(true)->setPublic(true));

        $container->compile();

        self::assertFalse($container->getDefinition('app.screen')->isShared());
    }

    public function testAScreenTaggedByHandIsNotSharedEither(): void
    {
        // Autoconfiguration only reaches services that opted into it, and an application
        // wiring a screen explicitly is exactly the case where it may not have.
        $container = $this->container();
        $container->setDefinition('app.screen', (new Definition(SharingProbeScreen::class))->setPublic(true)->addTag('native.screen'));

        $container->compile();

        self::assertFalse($container->getDefinition('app.screen')->isShared());
    }

    public function testAnExplicitSharedTrueIsLeftAlone(): void
    {
        // The tag is not a licence to overrule what an application asked for in writing.
        // ComponentScreenRenderer reports this one instead, naming the fix.
        $container = $this->container();
        $container->setDefinition('app.screen', (new Definition(SharingProbeScreen::class))
            ->setPublic(true)
            ->addTag('native.screen')
            ->setShared(true));

        $container->compile();

        self::assertTrue($container->getDefinition('app.screen')->isShared());
    }

    public function testTheRenderersOwnLocatorHandsBackAFreshInstance(): void
    {
        // The link the other tests only imply: a non-shared definition reached through a
        // real tagged service locator — the exact object the renderer is constructed with —
        // must construct per get(). A locator that cached would put the whole fix back to
        // where it started, and a hand-rolled test double would never show it.
        $container = $this->container();
        // Service id = class name, as the stock `App\:` glob registers them, so the locator
        // is keyed the way ComponentScreenRenderer looks screens up.
        $container->setDefinition(SharingProbeScreen::class, (new Definition(SharingProbeScreen::class))->setAutoconfigured(true));
        $container->setDefinition('app.renderer', (new Definition(ComponentScreenRenderer::class))
            ->setArguments([new ServiceLocatorArgument(new TaggedIteratorArgument('native.screen', null, null, true))])
            ->setPublic(true));

        $container->compile();

        $renderer = $container->get('app.renderer');
        self::assertInstanceOf(ComponentScreenRenderer::class, $renderer);

        $routes = new NativeRouteRegistry();
        $routes->register('/probe/{id}', SharingProbeScreen::class);
        $publisher = new ElementPublisher();
        $responder = new NativeScreenResponder($routes, $renderer, $publisher);

        $responder->respond('/probe/1');
        $responder->respond('/probe/2');

        // Second mount survived, and it is a different component: the first would have
        // rendered "probe 1" whatever the path said.
        $publisher->resetDiffState();
        self::assertSame('probe 2', $responder->republish()['props']['text'] ?? null);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', '/app');
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);

        // build() is where the autoconfiguration and the compiler pass are registered;
        // the extension itself is not loaded, so nothing else in the bundle is involved.
        (new NativeMobileBundle())->build($container);

        return $container;
    }
}

final class SharingProbeScreen extends NativeComponent
{
    private string $id = '';

    /** @param array<string, mixed> $parameters */
    public function withRouteParameters(array $parameters): void
    {
        $this->id = (string) ($parameters['id'] ?? '');
    }

    protected function render(): Element
    {
        return Text::make(trim('probe '.$this->id));
    }
}
