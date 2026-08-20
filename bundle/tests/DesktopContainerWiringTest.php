<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Contract\ProvidesPhpIni;
use Native\Symfony\Desktop\DependencyInjection\AliasContractsPass;
use Native\Symfony\Desktop\NativeDesktopBundle;
use Native\Symfony\Desktop\Testing\FakeRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The desktop container: every service built, and the two contracts aliased.
 *
 * Mobile got this treatment last; desktop's own compiler pass had no test at all, and
 * it is the piece that makes `implements AppBootstrapper` mean anything. Symfony does
 * not alias an interface to its implementation on its own — that is a Laravel habit —
 * so without the pass an application implements the contract, sees no error, and boots
 * to a window that never opens. Which is precisely the symptom the bundle's own warning
 * describes, and precisely the sort of thing that stays broken because it looks like
 * nothing happened.
 */
final class DesktopContainerWiringTest extends TestCase
{
    /** Below this the container has stopped registering the bundle. */
    private const MINIMUM_SERVICES = 30;

    public function testEveryServiceTheBundleDeclaresCanBeBuilt(): void
    {
        // testing: true so the transport is FakeRuntime — the alternative is a real
        // Client wanting an http_client this container has no FrameworkBundle to provide.
        $container = $this->compiled(['testing' => true]);

        $built = 0;

        foreach ($container->getServiceIds() as $id) {
            if (!str_starts_with($id, 'Native\Symfony\Desktop\\')) {
                continue;
            }

            self::assertIsObject($container->get($id), $id.' did not build.');
            ++$built;
        }

        self::assertGreaterThanOrEqual(self::MINIMUM_SERVICES, $built, 'Almost nothing was in the container.');
    }

    public function testEveryConsoleCommandIsBuildableAndNamed(): void
    {
        $container = $this->compiled(['testing' => true]);

        $commands = array_keys($container->findTaggedServiceIds('console.command'));

        self::assertGreaterThanOrEqual(8, \count($commands));

        foreach ($commands as $id) {
            $command = $container->get($id);

            self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);
            self::assertStringStartsWith('native:', (string) $command->getName(), $id);
        }
    }

    public function testTestingModeReplacesTheTransportAndNothingElse(): void
    {
        // The point of the flag: every manager, controller and the event bridge stay as
        // they are in production, so a functional test exercises the real payload building.
        $container = $this->compiled(['testing' => true]);

        self::assertInstanceOf(FakeRuntime::class, $container->get(ClientInterface::class));
        self::assertTrue($container->has(\Native\Symfony\Desktop\Window\WindowManager::class));
    }

    // ── the contracts an application implements ─────────────────────────────

    public function testAnAutoconfiguredBootstrapperReachesTheEndpointThatBootsIt(): void
    {
        // The end-to-end version of the question: not "is there an alias" but "does the
        // one service that consumes it get the application's implementation". Everything
        // between the two is what silently fails when the pass is missing.
        $container = $this->compiled(['testing' => true], static function (ContainerBuilder $container): void {
            $container->setDefinition('app.bootstrapper', (new Definition(ProbeBootstrapper::class))->setAutoconfigured(true)->setPublic(true));
        });

        $response = ($container->get(\Native\Symfony\Desktop\Http\BootedController::class))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $container->get('app.bootstrapper')->booted);
    }

    public function testAHandTaggedImplementationWorksToo(): void
    {
        // An application that wires the service itself never opts into autoconfiguration,
        // so the tag has to be honoured on its own.
        $container = $this->compiled(['testing' => true], static function (ContainerBuilder $container): void {
            $container->setDefinition('app.bootstrapper', (new Definition(ProbeBootstrapper::class))
                ->setPublic(true)
                ->addTag(AliasContractsPass::BOOTSTRAPPER_TAG));
        });

        self::assertInstanceOf(ProbeBootstrapper::class, $container->get(AppBootstrapper::class));
    }

    public function testWithoutTheAliasNothingConnectsTheContract(): void
    {
        // The failure this pass exists to prevent, reproduced: an implementation that is
        // neither tagged nor autoconfigured is invisible, and the app boots to no windows.
        $container = $this->compiled(['testing' => true], static function (ContainerBuilder $container): void {
            $container->setDefinition('app.bootstrapper', (new Definition(ProbeBootstrapper::class))
                ->setAutoconfigured(false)
                ->setPublic(true));
        });

        self::assertFalse($container->has(AppBootstrapper::class));

        $response = ($container->get(\Native\Symfony\Desktop\Http\BootedController::class))();

        // 500 with a message naming the contract, rather than a window that never opens.
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('AppBootstrapper', (string) $response->getContent());
    }

    public function testAnExplicitAliasInTheApplicationWins(): void
    {
        $container = $this->compiled(['testing' => true], static function (ContainerBuilder $container): void {
            $container->setDefinition('app.bootstrapper', (new Definition(ProbeBootstrapper::class))->setAutoconfigured(true));
            $container->setDefinition('app.preferred', (new Definition(PreferredBootstrapper::class))->setPublic(true));
            $container->setAlias(AppBootstrapper::class, 'app.preferred')->setPublic(true);
        });

        self::assertInstanceOf(PreferredBootstrapper::class, $container->get(AppBootstrapper::class));
    }

    public function testTwoImplementationsAreRefusedWithInstructions(): void
    {
        // Picking one silently would give an application a boot sequence it did not ask
        // for, and the two would differ by whichever was registered first.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Only one can be used/');

        $this->compiled(['testing' => true], static function (ContainerBuilder $container): void {
            $container->setDefinition('app.one', (new Definition(ProbeBootstrapper::class))->setAutoconfigured(true));
            $container->setDefinition('app.two', (new Definition(PreferredBootstrapper::class))->setAutoconfigured(true));
        });
    }

    public function testThePhpIniContractIsAliasedTheSameWay(): void
    {
        $container = $this->compiled(['testing' => true], static function (ContainerBuilder $container): void {
            $container->setDefinition('app.ini', (new Definition(ProbePhpIni::class))->setAutoconfigured(true)->setPublic(true));
        });

        self::assertInstanceOf(ProbePhpIni::class, $container->get(ProvidesPhpIni::class));

        // And it has to reach the command the runtime actually asks, or an application's
        // ini settings are computed and then dropped on the floor.
        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            $container->get(\Native\Symfony\Desktop\Command\PhpIniCommand::class),
        );
        $tester->execute([]);

        self::assertSame(['memory_limit' => '2G'], json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testWithNoImplementationTheContractStaysAbsent(): void
    {
        // Not an error: an app may have no ini provider at all. The bootstrapper's absence
        // is reported at request time by BootedController, which can say something useful.
        $container = $this->compiled(['testing' => true]);

        self::assertFalse($container->has(ProvidesPhpIni::class));
        self::assertFalse($container->has(AppBootstrapper::class));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>          $config
     * @param (callable(ContainerBuilder): void)|null $register
     */
    private function compiled(array $config = [], ?callable $register = null): ContainerBuilder
    {
        $bundle = new NativeDesktopBundle();

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir().'/np-desktop-wiring');
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir().'/np-desktop-wiring');

        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);

        // The handful of services FrameworkBundle would provide. The bundle decorates
        // event_dispatcher and reads request_stack, so a bare builder cannot compile —
        // which is why the pre-existing helper only ever *loaded* the extension and never
        // compiled it, and therefore never built a single service.
        $container->setDefinition('event_dispatcher', (new Definition(\Symfony\Component\EventDispatcher\EventDispatcher::class))->setPublic(true));
        $container->setDefinition('request_stack', (new Definition(\Symfony\Component\HttpFoundation\RequestStack::class))->setPublic(true));
        // Even with testing: true the real Client definition stays — only the alias moves —
        // so its dependency still has to resolve.
        $container->setDefinition('http_client', (new Definition(\Symfony\Component\HttpClient\MockHttpClient::class))->setPublic(true));

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $bundle->build($container);

        if (null !== $register) {
            $register($container);
        }

        // Public after the merge and before the removing passes, so that an unused private
        // service is built here rather than deleted — it is the one nobody has instantiated.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Native\\Symfony\\Desktop\\')) {
                        $definition->setPublic(true);
                    }
                }

                // The contract aliases are private by design — Symfony inlines and removes
                // those during compilation, so asking the compiled container for one says
                // "removed or inlined" rather than anything about the wiring. Public here
                // only, so the test can look at what the application's services received.
                foreach ($container->getAliases() as $id => $alias) {
                    if (str_starts_with($id, 'Native\\Symfony\\Desktop\\')) {
                        $alias->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_OPTIMIZE);

        $container->compile();

        return $container;
    }
}

final class ProbeBootstrapper implements AppBootstrapper
{
    public int $booted = 0;

    public function boot(): void
    {
        ++$this->booted;
    }
}

final class PreferredBootstrapper implements AppBootstrapper
{
    public function boot(): void
    {
    }
}

final class ProbePhpIni implements ProvidesPhpIni
{
    public function phpIni(): array
    {
        return ['memory_limit' => '2G'];
    }
}
