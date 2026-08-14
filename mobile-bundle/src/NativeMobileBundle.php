<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile;

use Native\Symfony\Mobile\Api;
use Native\Symfony\Mobile\Bridge\Bridge;
use Native\Symfony\Mobile\Bridge\BridgeInterface;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use Native\Symfony\Mobile\Runtime\MobileRuntimePatcher;
use Native\Symfony\Mobile\Runtime\ResponseEmitter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
                ->scalarNode('version')->defaultValue('1.0.0')->end()
                ->booleanNode('fake_bridge')
                    ->defaultFalse()
                    ->info(
                        'Swap the native bridge for a recording fake. The real bridge is a compiled '.
                        'PHP extension that only exists inside a packaged app, so this is how the '.
                        'mobile API is exercised in a test suite or in a browser during development.'
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

        // --- runtime ----------------------------------------------------------
        $services->set(ResponseEmitter::class)->public();
        $services->set(MobileRuntimePatcher::class);

        $services->set(Command\InstallCommand::class)
            ->args(['%kernel.project_dir%', service(MobileRuntimePatcher::class)])
            ->tag('console.command');

        $services->set(Command\DoctorCommand::class)
            ->args(['%kernel.project_dir%', service(BridgeInterface::class)])
            ->tag('console.command');
    }
}
