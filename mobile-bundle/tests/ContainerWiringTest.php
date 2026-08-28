<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Bridge\Bridge;
use Native\Symfony\Mobile\Bridge\BridgeInterface;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use Native\Symfony\Mobile\NativeMobileBundle;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenRenderer;
use Native\Symfony\Mobile\Ui\Routing\ScreenRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Every service this bundle declares, actually constructed.
 *
 * The extension is 165 statements of wiring and nothing instantiated any of it: the
 * suite builds the classes it tests by hand, so an argument list that no longer matches
 * a constructor, a service reference to something that was renamed, or a command that
 * cannot be built would all have passed a green suite and failed on first boot in an
 * application. Desktop's equivalent is exercised through its testing kit; mobile had no
 * equivalent at all.
 *
 * Definitions are forced public before compiling so that nothing is removed as unused —
 * an unused private definition is exactly the one nobody has ever instantiated.
 */
final class ContainerWiringTest extends TestCase
{
    /** Anything below this and the container has stopped registering the bundle. */
    private const MINIMUM_SERVICES = 30;

    public function testEveryServiceTheBundleDeclaresCanBeBuilt(): void
    {
        $container = $this->compiled();

        $built = 0;

        foreach ($this->ourServiceIds($container) as $id) {
            $service = $container->get($id);

            self::assertIsObject($service, $id.' did not build.');
            ++$built;
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_SERVICES,
            $built,
            'The container barely has anything in it, so this test is checking nothing.',
        );
    }

    public function testEveryConsoleCommandIsBuildableAndNamed(): void
    {
        $container = $this->compiled();

        $commands = array_keys($container->findTaggedServiceIds('console.command'));

        // native:mobile:{install,doctor,manifest,run,build} — the whole mobile CLI.
        self::assertCount(5, $commands);

        foreach ($commands as $id) {
            $command = $container->get($id);

            self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);
            self::assertStringStartsWith('native:mobile:', (string) $command->getName(), $id);
        }
    }

    public function testTheRealBridgeIsWiredByDefault(): void
    {
        $container = $this->compiled();

        self::assertInstanceOf(Bridge::class, $container->get(BridgeInterface::class));
    }

    public function testEveryApiClassIsWiredAndHoldsTheBridge(): void
    {
        // The extension names the API classes in a literal list, and `autowire(false)`
        // means one left out of it is a service the container simply does not have —
        // `$container->get(Api\Scanner::class)` throwing on first boot in an
        // application, with nothing here to say so first.
        $container = $this->compiled();

        foreach (glob(__DIR__.'/../src/Api/*.php') ?: [] as $file) {
            $class = 'Native\Symfony\Mobile\Api\\'.basename($file, '.php');

            self::assertTrue($container->has($class), $class.' is not registered by the extension.');
            self::assertSame($container->get(BridgeInterface::class), $this->bridgeOf($container->get($class)), $class);
        }
    }

    public function testFakeBridgeReplacesItWhereverItIsInjected(): void
    {
        // Not just the alias: every one of the 17 API classes takes the interface, so a
        // fake that only replaced the alias would leave them talking to a bridge that is
        // unavailable outside a device — which is the whole point of the flag.
        $container = $this->compiled(['fake_bridge' => true]);

        self::assertInstanceOf(FakeBridge::class, $container->get(BridgeInterface::class));
        self::assertTrue($container->get(BridgeInterface::class)->isAvailable());

        $camera = $container->get(\Native\Symfony\Mobile\Api\Camera::class);

        // The API object holds the same instance, so what a test scripts on the fake is
        // what the API sees.
        self::assertSame($container->get(BridgeInterface::class), $this->bridgeOf($camera));
    }

    public function testTheScreenRendererIsAliasedSoAnApplicationCanReplaceIt(): void
    {
        $container = $this->compiled();

        self::assertInstanceOf(ComponentScreenRenderer::class, $container->get(ScreenRendererInterface::class));
        self::assertTrue($container->hasAlias(ScreenRendererInterface::class));
    }

    public function testConfigurationReachesTheServicesThatReadIt(): void
    {
        $container = $this->compiled([
            'app_id' => 'com.acme.pad',
            'name' => 'Pad',
            'version' => '4.5.6',
            'platform' => 'android',
        ]);

        self::assertSame('com.acme.pad', $container->getParameter('native_mobile.app_id'));
        self::assertSame('4.5.6', $container->getParameter('native_mobile.version'));

        // The manifest refuses an empty version, and it takes it as a constructor
        // argument rather than reading config itself — so a parameter that never
        // arrived would be a device silently ignoring the route dump.
        $manifest = $container->get(\Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest::class);

        self::assertSame('4.5.6', $manifest->version());
    }

    public function testTheTwigExtensionIsRegisteredWhenTwigIsInstalled(): void
    {
        $container = $this->compiled();

        self::assertTrue(
            $container->has(\Native\Symfony\Mobile\Ui\Twig\NativeUiExtension::class),
            'twig/twig is installed here, so the authoring extension has to be registered.',
        );
        self::assertNotSame([], $container->findTaggedServiceIds('twig.extension'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $config */
    private function compiled(array $config = []): ContainerBuilder
    {
        $bundle = new NativeMobileBundle();

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir().'/np-wiring');
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir().'/np-wiring');

        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $bundle->build($container);

        // The extension is resolved into definitions during compilation, so anything of
        // ours has to be made public from inside it — after the merge, before the removing
        // passes. Otherwise every private service is inlined or dropped, and a private
        // service nobody has ever instantiated is precisely what this test is for.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Native\\Symfony\\Mobile\\')) {
                        $definition->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_OPTIMIZE);

        $container->compile();

        return $container;
    }

    /** @return list<string> */
    private function ourServiceIds(ContainerBuilder $container): array
    {
        $ids = [];

        foreach ($container->getServiceIds() as $id) {
            if (!str_starts_with($id, 'Native\Symfony\Mobile\\')) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function bridgeOf(object $api): ?object
    {
        $property = new \ReflectionProperty($api, 'bridge');

        return $property->getValue($api);
    }
}
