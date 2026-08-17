<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\DependencyInjection;

use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Contract\ProvidesPhpIni;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Aliases the bundle's two app-facing contracts to whichever service implements
 * them, so an application only has to write the class.
 *
 * Symfony does not alias interfaces to implementations on its own — that is a
 * Laravel habit. Without this, implementing AppBootstrapper appears to do
 * nothing: the app boots to no windows and looks like it hung. (The bundle warns
 * about exactly that, which is how this gap was found.)
 *
 * Autoconfiguration tags the implementations; this pass picks the winner.
 */
final class AliasContractsPass implements CompilerPassInterface
{
    public const BOOTSTRAPPER_TAG = 'native_desktop.bootstrapper';
    public const PHP_INI_TAG = 'native_desktop.php_ini_provider';

    public function process(ContainerBuilder $container): void
    {
        $this->alias($container, self::BOOTSTRAPPER_TAG, AppBootstrapper::class);
        $this->alias($container, self::PHP_INI_TAG, ProvidesPhpIni::class);
    }

    private function alias(ContainerBuilder $container, string $tag, string $interface): void
    {
        // An explicit alias in the app's own config always wins.
        if ($container->has($interface)) {
            return;
        }

        $ids = array_keys($container->findTaggedServiceIds($tag));

        if ([] === $ids) {
            return;
        }

        if (\count($ids) > 1) {
            throw new \LogicException(sprintf(
                'Found %d services implementing %s (%s). Only one can be used — alias %s explicitly '.
                'in your services config to choose.',
                \count($ids),
                $interface,
                implode(', ', $ids),
                $interface,
            ));
        }

        $container->setAlias($interface, $ids[0])->setPublic(false);
    }
}
