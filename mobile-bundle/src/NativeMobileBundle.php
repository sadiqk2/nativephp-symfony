<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile;

use Native\Symfony\Mobile\Api;
use Native\Symfony\Mobile\Build;
use Native\Symfony\Mobile\Bridge\Bridge;
use Native\Symfony\Mobile\Bridge\BridgeInterface;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use Native\Symfony\Mobile\DependencyInjection\NativeScreenSharingPass;
use Native\Symfony\Mobile\Runtime\MobileRuntimePatcher;
use Native\Symfony\Mobile\Runtime\ResponseEmitter;
use Native\Symfony\Mobile\Ui\ElementFactory;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenFactory;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenRenderer;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenAttributeLoader;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenResponder;
use Native\Symfony\Mobile\Ui\Routing\ScreenRendererInterface;
use Native\Symfony\Mobile\Ui\Style\StyleApplier;
use Native\Symfony\Mobile\Ui\Style\StyleParser;
use Native\Symfony\Mobile\Ui\Style\ThemeColorResolverInterface;
use Native\Symfony\Mobile\Ui\Twig\NativeUiExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

final class NativeMobileBundle extends AbstractBundle
{
    protected string $extensionAlias = 'native_mobile';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('app_id')
                    ->defaultValue('com.example.app')
                    ->info('Bundle identifier / Android application id.')
                ->end()
                ->scalarNode('name')->defaultValue('App')->end()
                ->scalarNode('version')
                    ->defaultValue('1.0.0')
                    ->cannotBeEmpty()
                    ->info(
                        'Must be a non-empty string. YAML `version: 1.0` is a float and the '.
                        'container coerces it to "1" — and the hosts compare this by string '.
                        'equality, so a coerced value silently disables the runtime route dump. '.
                        'Quote it.'
                    )
                    // Rejected rather than coerced. By the time this runs, PHP has already
                    // parsed `1.0` as a float and lost the difference between 1.0 and 1 —
                    // so any normalisation here would silently pick one, which is the bug
                    // rather than the fix. Better to make the author write what they mean.
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !\is_string($v))
                        ->thenInvalid(
                            'native_mobile.version must be a quoted string, got %s. The hosts '.
                            'compare it by string equality, and an unquoted 1.0 reaches the '.
                            'container as "1" — which silently disables the runtime route dump.'
                        )
                    ->end()
                ->end()
                ->booleanNode('fake_bridge')
                    ->defaultFalse()
                    ->info(
                        'Swap the native bridge for a recording fake. The real bridge is a compiled '.
                        'PHP extension that only exists inside a packaged app, so this is how the '.
                        'mobile API is exercised in a test suite or in a browser during development.'
                    )
                ->end()
                ->scalarNode('platform')
                    ->defaultNull()
                    ->info(
                        'ios or android, for resolving platform-variant style classes. '.
                        'Null drops every ios:/android: class, which is the safe default '.
                        'off a device but wrong on one — set it per request from the '.
                        'device info if you use those variants.'
                    )
                ->end()
                ->booleanNode('collect_garbage')
                    ->defaultFalse()
                    ->info(
                        'Run gc_collect_cycles() after each request in persistent mode. Costs time '.
                        'per request; worth it only for an app that holds large object graphs.'
                    )
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Screens registered as services become reachable by class name through the
        // renderer's locator, so a screen with constructor dependencies works without the
        // app wiring anything. A screen with none needs no registration at all — the
        // renderer instantiates it directly.
        //
        // Not shared, which is not a preference: a component may be bound to exactly one
        // tree, and `bind()` throws on a second attempt because reusing an instance would
        // carry its state and children into the next screen. A shared service is the same
        // object every time the locator is asked, so the second mount of any screen — a
        // back-navigation, or merely /user/1 to /user/2 — would throw. Under the stock
        // `App\:` glob every screen in an application is a service, so that is the default
        // path, not an exotic one.
        $container->registerForAutoconfiguration(NativeComponent::class)
            ->addTag('native.screen')
            ->setShared(false);

        // Autoconfiguration only reaches services that opted into it. A screen wired by
        // hand with `autoconfigure: false`, or tagged explicitly, would still arrive
        // shared, so the guarantee is enforced over the tag itself as well.
        $container->addCompilerPass(new NativeScreenSharingPass());
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire(false);

        $container->parameters()
            ->set('native_mobile.app_id', $config['app_id'])
            ->set('native_mobile.name', $config['name'])
            ->set('native_mobile.version', $config['version'])
            ->set('native_mobile.collect_garbage', $config['collect_garbage']);

        // --- bridge -----------------------------------------------------------
        if ($config['fake_bridge']) {
            $services->set(FakeBridge::class)->args([true])->public();
            $services->alias(BridgeInterface::class, FakeBridge::class)->public();
        } else {
            $services->set(Bridge::class)
                ->args([service('logger')->nullOnInvalid()])
                ->tag('monolog.logger', ['channel' => 'native_mobile']);
            $services->alias(BridgeInterface::class, Bridge::class)->public();
        }

        // --- the 54-method API surface ---------------------------------------
        foreach ([
            Api\Biometric::class,
            Api\Browser::class,
            Api\Camera::class,
            Api\Device::class,
            Api\Dialog::class,
            Api\Files::class,
            Api\Geolocation::class,
            Api\Microphone::class,
            Api\MobileWallet::class,
            Api\NativeUi::class,
            Api\Network::class,
            Api\Performance::class,
            Api\PushNotifications::class,
            Api\SecureStorage::class,
            Api\Share::class,
            Api\System::class,
        ] as $api) {
            $services->set($api)->args([service(BridgeInterface::class)])->public();
        }

        // --- native UI: styling ------------------------------------------------
        // The theme resolver is the app's to provide; without one, every theme-*
        // class resolves to nothing rather than to a guessed colour.
        $services->set(StyleParser::class)
            ->args([
                service(ThemeColorResolverInterface::class)->nullOnInvalid(),
                $config['platform'],
            ])
            ->public();

        // Without the applier the parser is decorative: it emits a camelCase
        // intermediate vocabulary, and camelCase keys on the wire are silently ignored by
        // every renderer.
        $services->set(StyleApplier::class)
            ->args([service(StyleParser::class)])
            ->public();

        // --- native UI: routing ------------------------------------------------
        $services->set(NativeRouteRegistry::class)->public();

        $services->set(NativeScreenAttributeLoader::class)
            ->args([service(NativeRouteRegistry::class)])
            ->public();

        // The version is a constructor argument, not config the manifest reads for itself:
        // it has to be a non-empty string or the device silently ignores the runtime dump.
        $services->set(NativeRouteManifest::class)
            ->args([service(NativeRouteRegistry::class), '%native_mobile.version%'])
            ->public();

        // Needs a renderer, which the component layer provides. nullOnInvalid so an app
        // using only the WebView path is not forced to register one.
        $services->set(NativeScreenResponder::class)
            ->args([
                service(NativeRouteRegistry::class),
                service(ScreenRendererInterface::class)->nullOnInvalid(),
                service(ElementPublisher::class),
            ])
            ->public();

        // --- native UI: components ---------------------------------------------
        // Deliberately NOT tagged kernel.reset, and neither is anything it produces:
        // MobileRuntime resets tagged services after every dispatch, which would clear
        // component state under a live screen. That reads as a random UI reset on a
        // device and never reproduces in a test.
        $services->set(ComponentScreenFactory::class)
            ->args([service(ElementPublisher::class)])
            ->public();

        // The join between routing and components. Both halves existed and nothing
        // connected them, so every app had to write this itself.
        //
        // Aliased rather than registered directly as the interface so an app that wants a
        // different renderer — a Twig-rendered or controller-driven screen — can override
        // the alias in its own configuration, which is loaded after this.
        $services->set(ComponentScreenRenderer::class)
            ->args([tagged_locator('native.screen')])
            ->public();

        $services->alias(ScreenRendererInterface::class, ComponentScreenRenderer::class)
            ->public();

        // --- native UI (the element-tree path) --------------------------------
        // Registered unconditionally: an app on the WebView path simply never
        // publishes a frame, and the services cost nothing unused.
        $services->set(ElementFactory::class)
            ->args([[], service(StyleApplier::class)])
            ->public();
        $services->set(ElementPublisher::class)->public();

        if (class_exists(\Twig\Extension\AbstractExtension::class)) {
            $services->set(NativeUiExtension::class)
                ->args([
                    service(ElementFactory::class),
                    service(ElementPublisher::class),
                    service(StyleApplier::class),
                ])
                ->tag('twig.extension');
        }

        // --- runtime ----------------------------------------------------------
        $services->set(ResponseEmitter::class)->public();
        $services->set(MobileRuntimePatcher::class);

        $services->set(Command\InstallCommand::class)
            ->args(['%kernel.project_dir%', service(MobileRuntimePatcher::class)])
            ->tag('console.command');

        $services->set(Command\DoctorCommand::class)
            ->args(['%kernel.project_dir%', service(BridgeInterface::class)])
            ->tag('console.command');

        // --- build tooling -----------------------------------------------------
        // Toolchain reads the environment once, at construction, through a factory rather
        // than getenv() inside the class: detection has to be testable without mutating
        // the process environment.
        $services->set(Build\Toolchain::class)->factory([Build\Toolchain::class, 'fromEnvironment']);
        $services->set(Build\BundleMetaWriter::class);
        $services->set(Build\ProcessRunner::class);
        $services->alias(Build\CommandRunnerInterface::class, Build\ProcessRunner::class);

        $services->set(Command\MobileManifestCommand::class)
            ->args([
                '%kernel.project_dir%',
                service(NativeRouteManifest::class),
                service(Build\BundleMetaWriter::class),
            ])
            ->tag('console.command');

        $services->set(Command\MobileRunCommand::class)
            ->args([
                '%kernel.project_dir%',
                '%native_mobile.app_id%',
                service(Build\Toolchain::class),
                service(Build\CommandRunnerInterface::class),
            ])
            ->tag('console.command');

        $services->set(Command\MobileBuildCommand::class)
            ->args([
                '%kernel.project_dir%',
                '%native_mobile.version%',
                service(NativeRouteManifest::class),
                service(Build\Toolchain::class),
                service(Build\CommandRunnerInterface::class),
            ])
            ->tag('console.command');
    }
}
