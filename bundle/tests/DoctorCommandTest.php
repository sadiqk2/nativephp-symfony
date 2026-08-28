<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Command\DoctorCommand;
use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Http\BootedController;
use Native\Symfony\Desktop\Http\EventsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Router;

/**
 * `native:doctor`.
 *
 * Both hard checks here exist because the failure they catch is invisible: the
 * runtime's half of the conversation is HTTP, so a request it cannot land is
 * logged by Electron and nowhere else. The app starts, the window opens, and it
 * sits there empty. The routes case cost real time once already; the firewall
 * case is the same failure reachable from an app's own security.yaml.
 */
final class DoctorCommandTest extends TestCase
{
    /** How every SPA front end is routed, and what an app's own routing file holds. */
    private const CATCH_ALL = "    \$routes->add('app_spa', '/{path}')->controller('App\\\\Controller\\\\SpaController')->requirements(['path' => '.*'])->defaults(['path' => ''])";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/np-doctor-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testItPassesWhenTheRuntimeEndpointsRoute(): void
    {
        $tester = $this->doctor(router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Everything the runtime needs is in place', $tester->getDisplay());
    }

    public function testItFailsWhenTheRoutesImportIsMissing(): void
    {
        $tester = $this->doctor(router: $this->routerMatching([]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // SymfonyStyle wraps its blocks at the terminal width, so assert on
        // fragments short enough to survive the wrap rather than whole sentences.
        self::assertStringContainsString('not registered', $tester->getDisplay());
        self::assertStringContainsString('native_desktop', $tester->getDisplay());
    }

    public function testItFailsWhenOnlyOneOfTheTwoEndpointsRoutes(): void
    {
        // A hand-written import that covers /booted but not /events leaves an app
        // that starts correctly and then never receives a menu click.
        $tester = $this->doctor(router: $this->routerMatching(['/_native/api/booted']));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testItFailsWhenTheEndpointsAreOnlyReachableByGet(): void
    {
        // The runtime only ever POSTs, so a GET-only registration is as dead as none.
        $router = new class implements RequestMatcherInterface {
            public function matchRequest(Request $request): array
            {
                if ('GET' !== $request->getMethod()) {
                    throw new ResourceNotFoundException();
                }

                return ['_route' => 'whatever'];
            }
        };

        self::assertSame(Command::FAILURE, $this->doctor(router: $router)->getStatusCode());
    }

    public function testItFailsWhenAccessControlCoversTheRuntimeEndpoints(): void
    {
        // RuntimeAccessSubscriber returns without stopping propagation, so an
        // `access_control` rule of ^/ still evaluates and redirects the runtime's
        // POST to a login page. In Laravel these routes sit outside the app's
        // middleware groups, so nothing equivalent bites there.
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            exemptRuntimeFirewall: false,
            accessMap: $this->accessMap(['IS_AUTHENTICATED_FULLY']),
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('behind access_control', $tester->getDisplay());
        self::assertStringContainsString('security: false', $tester->getDisplay());
    }

    public function testAccessControlIsNotAFailureWhenTheFirewallHandlingItHasSecurityDisabled(): void
    {
        // The false positive that matters most: `access_control: ^/` stays in the
        // access map even when a `security: false` firewall covers the path, because
        // the rule is applied by the firewall's AccessListener and that listener
        // never runs. Reading the map alone therefore warns at the one reader who
        // has already written the exemption this command recommends. Verified
        // against a real security-bundle both ways, not just against this stub.
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            exemptRuntimeFirewall: false,
            accessMap: $this->accessMap(['IS_AUTHENTICATED_FULLY']),
            firewallMap: $this->firewallMap(securityEnabled: false),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('security disabled', $tester->getDisplay());
    }

    public function testAccessControlIsAFailureWhenTheFirewallHandlingItIsActive(): void
    {
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            exemptRuntimeFirewall: false,
            accessMap: $this->accessMap(['IS_AUTHENTICATED_FULLY']),
            firewallMap: $this->firewallMap(securityEnabled: true),
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testAPathNoFirewallHandlesCannotBeGated(): void
    {
        // Nothing enforces access_control without a firewall to enforce it.
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            exemptRuntimeFirewall: false,
            accessMap: $this->accessMap(['IS_AUTHENTICATED_FULLY']),
            firewallMap: $this->firewallMap(securityEnabled: null),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAnAccessMapThatCoversNothingPasses(): void
    {
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            exemptRuntimeFirewall: false,
            accessMap: $this->accessMap([]),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('not behind access_control', $tester->getDisplay());
    }

    public function testTheBundlesOwnExemptionMakesTheFirewallCheckMoot(): void
    {
        // Default configuration: RuntimeRoutesAccessMap neutralises access_control on
        // these paths inside the runtime, so an app with a rule of ^/ is fine and must
        // not be warned at. Checking the map here would report the console's answer
        // anyway — `running` is false on a command line, so the decorator passes
        // straight through and every healthy app would look broken.
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            accessMap: $this->accessMap(['IS_AUTHENTICATED_FULLY']),
            firewallMap: $this->firewallMap(securityEnabled: true),
            exemptRuntimeFirewall: true,
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('neutralised', $tester->getDisplay());
    }

    public function testTheExemptionIsNotClaimedWhenTheSecretGateIsOff(): void
    {
        // `block_browser_access: false` is one documented line, and it also switches
        // off the exemption: RuntimeRoutesAccessMap hands the app's rules away only
        // while the shared secret is enforcing in their place. So the app's `^/` rule
        // does still cover the runtime endpoints, the runtime's POST is denied, and
        // boot() never runs — the exact failure this command exists to name.
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            accessMap: $this->accessMap(['IS_AUTHENTICATED_FULLY']),
            firewallMap: $this->firewallMap(securityEnabled: true),
            exemptRuntimeFirewall: true,
            blockBrowserAccess: false,
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('behind access_control', $tester->getDisplay());
        // And the advice must not be "set exempt_runtime_firewall: true", which is
        // already true and is not what is stopping the exemption.
        self::assertStringContainsString('block_browser_access', $tester->getDisplay());
    }

    public function testWithoutTheSecurityBundleTheFirewallCheckIsSkippedRatherThanAssumed(): void
    {
        $tester = $this->doctor(router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']));

        self::assertStringContainsString('no security bundle installed', $tester->getDisplay());
    }

    public function testAMissingBootstrapperIsReportedButNotAFailure(): void
    {
        // An app may open its windows some other way; the container cannot tell.
        $tester = $this->doctor(router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('no AppBootstrapper is registered', $tester->getDisplay());
    }

    public function testARegisteredBootstrapperIsNamed(): void
    {
        $bootstrapper = new class implements AppBootstrapper {
            public function boot(): void
            {
            }
        };

        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            bootstrapper: $bootstrapper,
        );

        // The class of an anonymous class carries its file and line, which the
        // wrap will break; the interface name in front of it is enough.
        self::assertStringContainsString('AppBootstrapper@anonymous', $tester->getDisplay());
        self::assertStringContainsString('will run when the runtime finishes booting', $tester->getDisplay());
    }

    public function testAnAbsentSecretInsideTheRuntimeIsCalledOut(): void
    {
        // The subscriber fails open without a secret, which is the right call and
        // also means nothing else would ever mention it.
        $tester = $this->doctor(
            router: $this->routerMatching(['/_native/api/booted', '/_native/api/events']),
            running: true,
            secret: null,
        );

        self::assertStringContainsString('ABSENT', $tester->getDisplay());
    }

    public function testItReportsRatherThanFailsWhenThereIsNoRouter(): void
    {
        $tester = $this->doctor(router: null);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No router is available', $tester->getDisplay());
    }

    public function testItFailsWhenAnApplicationCatchAllSwallowsTheRuntimeEndpoints(): void
    {
        // The check this replaces asked only whether the paths routed *somewhere*.
        // Every SPA is wired with a `/{path}` front-end route, and if it is declared
        // before the bundle's import the router hands both endpoints to it — the
        // compiled matcher demotes a static route behind an earlier dynamic one that
        // covers it, precisely to keep declaration order. So the runtime POSTs, the
        // app's own controller answers with its index page, boot() never runs, and
        // the one command that reports on this used to print a tick.
        $tester = $this->doctor(router: $this->appRouter(
            self::CATCH_ALL.";\n".self::bundleImport(),
        ));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('SpaController', $tester->getDisplay());
        self::assertStringContainsString('before', $tester->getDisplay());
    }

    public function testItFailsWhenAnApplicationRouteShadowsTheBundlesRouteName(): void
    {
        // Why the match's controller and not its route name: RouteCollection::add
        // replaces silently by name, so a second, stale native_desktop.yaml — or a
        // hand-written import that kept the bundle's names — leaves `_route` reading
        // native_desktop_booted while the app's controller is what runs. Nothing
        // anywhere warns about the collision.
        $tester = $this->doctor(router: $this->appRouter(
            self::bundleImport()."\n".
            "    \$routes->add('native_desktop_booted', '/_native/api/booted')".
            "->controller('App\\\\Controller\\\\Shadow')->methods(['POST']);",
        ));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('App\\Controller\\Shadow', $tester->getDisplay());
    }

    public function testTheBundlesOwnRoutesImportPasses(): void
    {
        // Drives the real routes.php, so the constant naming the two controllers
        // cannot drift from the file that registers them.
        $tester = $this->doctor(router: $this->appRouter(self::bundleImport()));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(' ✓ POST /_native/api/booted', $tester->getDisplay());
    }

    public function testACatchAllDeclaredAfterTheImportIsNotAFailure(): void
    {
        // The false positive to avoid: this is the correct wiring, and it is what
        // `native:install` produces. A later dynamic route never displaces a static
        // one, so both endpoints still reach the bundle.
        $tester = $this->doctor(router: $this->appRouter(
            self::bundleImport()."\n".self::CATCH_ALL.';',
        ));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testACatchAllRestrictedToGetIsNotAFailure(): void
    {
        // Also not a failure, for a reason worth pinning: the matcher records the
        // method mismatch and keeps looking, so a GET-only front-end route declared
        // first still leaves the runtime's POST with the bundle's controller.
        $tester = $this->doctor(router: $this->appRouter(
            self::CATCH_ALL."->methods(['GET']);\n".self::bundleImport(),
        ));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAHandWrittenImportNamingTheInvokeMethodPasses(): void
    {
        // `controller: Native\...\BootedController::__invoke` is the same controller
        // written the long way, and the routing docs show that form, so comparing the
        // whole `_controller` string would report a working app as broken.
        $tester = $this->doctor(router: $this->appRouter(
            "    \$routes->add('booted', '/_native/api/booted')->controller('".
            addslashes(BootedController::class)."::__invoke')->methods(['POST']);\n".
            "    \$routes->add('events', '/_native/api/events')->controller('".
            addslashes(EventsController::class)."::__invoke')->methods(['POST']);",
        ));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testARouteWithNoControllerAtAllIsAFailure(): void
    {
        $tester = $this->doctor(router: $this->appRouter(
            "    \$routes->add('booted', '/_native/api/booted')->methods(['POST']);\n".
            "    \$routes->add('events', '/_native/api/events')->methods(['POST']);",
        ));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no controller', $tester->getDisplay());
    }

    /** The line `native:install` writes into config/routes/native_desktop.yaml. */
    private static function bundleImport(): string
    {
        return "    \$routes->import('".\dirname(__DIR__)."/src/Resources/config/routes.php', 'php');";
    }

    /**
     * A real compiled Router over an application routing file, which is the only
     * thing that can answer an ordering question: a stub matcher has no order.
     */
    private function appRouter(string $body): RequestMatcherInterface
    {
        (new Filesystem())->mkdir($this->root.'/cache');

        file_put_contents(
            $this->root.'/app_routes.php',
            "<?php\n\nuse Symfony\\Component\\Routing\\Loader\\Configurator\\RoutingConfigurator;\n\n".
            "return static function (RoutingConfigurator \$routes): void {\n".$body."\n};\n",
        );

        return new Router(
            new PhpFileLoader(new FileLocator([$this->root])),
            'app_routes.php',
            ['cache_dir' => $this->root.'/cache'],
            new RequestContext(),
        );
    }

    /** @param list<string> $paths */
    private function routerMatching(array $paths): RequestMatcherInterface
    {
        return new class($paths) implements RequestMatcherInterface {
            /** @param list<string> $paths */
            public function __construct(private readonly array $paths)
            {
            }

            public function matchRequest(Request $request): array
            {
                if (!\in_array($request->getPathInfo(), $this->paths, true)) {
                    throw new ResourceNotFoundException($request->getPathInfo());
                }

                return [
                    '_route' => 'native_desktop',
                    '_controller' => '/_native/api/booted' === $request->getPathInfo()
                        ? BootedController::class
                        : EventsController::class,
                ];
            }
        };
    }

    /**
     * Stands in for Symfony's `security.access_map`, which the command takes
     * untyped so that installing this bundle does not drag in security-http.
     *
     * @param list<string> $attributes
     */
    private function accessMap(array $attributes): object
    {
        return new class($attributes) {
            /** @param list<string> $attributes */
            public function __construct(private readonly array $attributes)
            {
            }

            /** @return array{0: ?array<string>, 1: ?string} */
            public function getPatterns(Request $request): array
            {
                return [[] === $this->attributes ? null : $this->attributes, null];
            }
        };
    }

    /**
     * Stands in for `security.firewall.map`. A null $securityEnabled means no
     * firewall matches the path at all.
     */
    private function firewallMap(?bool $securityEnabled): object
    {
        return new class($securityEnabled) {
            public function __construct(private readonly ?bool $securityEnabled)
            {
            }

            public function getFirewallConfig(Request $request): ?object
            {
                if (null === $this->securityEnabled) {
                    return null;
                }

                return new class($this->securityEnabled) {
                    public function __construct(private readonly bool $enabled)
                    {
                    }

                    public function isSecurityEnabled(): bool
                    {
                        return $this->enabled;
                    }
                };
            }
        };
    }

    private function doctor(
        ?RequestMatcherInterface $router,
        ?object $accessMap = null,
        ?AppBootstrapper $bootstrapper = null,
        bool $running = false,
        ?string $secret = 'abcdefghijklmnopqrstuvwxyz012345',
        ?object $firewallMap = null,
        bool $exemptRuntimeFirewall = false,
        bool $blockBrowserAccess = true,
    ): CommandTester {
        $tester = new CommandTester(new DoctorCommand(
            projectDir: sys_get_temp_dir(),
            running: $running,
            secret: $secret,
            router: $router,
            bootstrapper: $bootstrapper,
            accessMap: $accessMap,
            firewallMap: $firewallMap,
            exemptRuntimeFirewall: $exemptRuntimeFirewall,
            blockBrowserAccess: $blockBrowserAccess,
        ));

        $tester->execute([]);

        return $tester;
    }
}
