<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Runtime\MobileRuntime;
use Native\Symfony\Mobile\Runtime\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The persistent runtime, driven through a real Symfony kernel.
 *
 * This is as close as this environment gets to proving mobile works: no device, no
 * Android SDK, no Xcode — but a genuine kernel booted once and serving many
 * requests, which is the mechanism the whole mobile port rests on.
 */
final class MobileRuntimeTest extends TestCase
{
    private int $handlerBaseline = 0;
    private int $errorBaseline = 0;

    protected function setUp(): void
    {
        MobileRuntime::reset();
        $this->clearCache();
        $this->handlerBaseline = self::exceptionHandlerDepth();
        $this->errorBaseline = self::errorHandlerDepth();
    }

    protected function tearDown(): void
    {
        MobileRuntime::reset();
        $this->clearCache();
        $this->drainHandlers();
    }

    /**
     * Booting a kernel installs Symfony's error and exception handlers globally and
     * nothing removes them, which PHPUnit reports as risky.
     *
     * Pop back to the depth recorded in setUp — measured, not guessed. Restoring a
     * fixed number of times either leaves handlers behind or removes PHPUnit's own,
     * and PHPUnit reports both.
     */
    private function drainHandlers(): void
    {
        // Drained independently: the two stacks are separate, and the kernel does
        // not necessarily add the same number to each. Popping them in lockstep
        // removes PHPUnit's own error handler, which it also reports.
        while (self::exceptionHandlerDepth() > $this->handlerBaseline) {
            restore_exception_handler();
        }

        while (self::errorHandlerDepth() > $this->errorBaseline) {
            restore_error_handler();
        }
    }

    /** As exceptionHandlerDepth(), for the error-handler stack. */
    private static function errorHandlerDepth(): int
    {
        $handlers = [];

        while (\count($handlers) < 32) {
            $handler = set_error_handler(null);
            restore_error_handler();

            if (null === $handler) {
                break;
            }

            $handlers[] = $handler;
            restore_error_handler();
        }

        foreach (array_reverse($handlers) as $handler) {
            set_error_handler($handler);
        }

        return \count($handlers);
    }

    /**
     * How many exception handlers are on the stack, without disturbing it.
     *
     * PHP exposes no depth API, so this walks the stack by popping and puts every
     * handler back in order afterwards.
     */
    private static function exceptionHandlerDepth(): int
    {
        $handlers = [];

        while (\count($handlers) < 32) {
            $handler = set_exception_handler(null);
            restore_exception_handler();

            if (null === $handler) {
                break;
            }

            $handlers[] = $handler;
            restore_exception_handler();
        }

        foreach (array_reverse($handlers) as $handler) {
            set_exception_handler($handler);
        }

        return \count($handlers);
    }

    public function testItBootsOnceAndServesManyRequests(): void
    {
        $runtime = MobileRuntime::boot(new RuntimeTestKernel());

        for ($i = 1; $i <= 3; ++$i) {
            [$request] = ServerRequestFactory::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/ping',
            ]);

            $response = $runtime->handle($request);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('pong', $response->getContent());
        }

        // The point of the persistent path: one boot, three requests.
        self::assertSame(3, $runtime->dispatchCount());
        self::assertSame(1, RuntimeTestKernel::$boots);
    }

    public function testStateDoesNotLeakBetweenRequests(): void
    {
        // services_resetter is what makes this true, and it is why the Symfony port
        // does not need Laravel's hand-rolled facade/router/Livewire resetting.
        $runtime = MobileRuntime::boot(new RuntimeTestKernel());

        foreach ([1, 2, 3] as $expected) {
            [$request] = ServerRequestFactory::fromServer([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/counter',
            ]);

            // Each request should see a freshly-reset counter service, so the count
            // is always 1 — not 1, 2, 3.
            self::assertSame('1', $runtime->handle($request)->getContent());
        }
    }

    public function testAThrowingControllerBecomesA500RatherThanKillingTheApp(): void
    {
        // A fatal here would take down the whole app rather than one screen, and the
        // native layer has no way to recover.
        $runtime = MobileRuntime::boot(new RuntimeTestKernel());

        [$request] = ServerRequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/boom',
        ]);

        $response = $runtime->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('deliberate', (string) $response->getContent());

        // Still alive afterwards — the failure was contained.
        [$next] = ServerRequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/ping']);
        self::assertSame(200, $runtime->handle($next)->getStatusCode());
    }

    public function testItEmitsARawHttpMessageForTheNativeLayer(): void
    {
        $runtime = MobileRuntime::boot(new RuntimeTestKernel());

        [$request] = ServerRequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/ping']);

        ob_start();
        $runtime->emit($request, ['X-PHP-Timing' => 'total=1ms,mode=persistent']);
        $written = (string) ob_get_clean();

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $written);
        self::assertStringContainsString('X-PHP-Timing: total=1ms,mode=persistent', $written);
        self::assertStringEndsWith('pong', $written);
    }

    public function testDispatchingBeforeBootIsARefusalNotACrash(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/has not been booted/');

        MobileRuntime::instance();
    }

    public function testCookiesSetByTheApplicationReachTheNativeLayer(): void
    {
        // The session is the thing most likely to be silently lost across the
        // hand-rolled response boundary.
        $runtime = MobileRuntime::boot(new RuntimeTestKernel());

        [$request] = ServerRequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/set-cookie']);

        ob_start();
        $runtime->emit($request);
        $written = (string) ob_get_clean();

        self::assertStringContainsString('Set-Cookie: visited=yes', $written);
    }

    // ── the static entry points the hosts evaluate ──────────────────────────

    public function testTheHostsDispatchEntryPointReturnsAResponse(): void
    {
        // Android's php_bridge.c and iOS's PHP.c compile
        // `$__response = …::dispatch(…);` into themselves and then echo the status line,
        // the headers and the body from what they get back — so this has to return the
        // response rather than write one, and it has to be reachable statically.
        MobileRuntime::boot(new RuntimeTestKernel());

        [$request] = ServerRequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/ping']);

        $response = MobileRuntime::dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pong', $response->getContent());
        self::assertSame(1, MobileRuntime::instance()->dispatchCount());
    }

    public function testTheHostsConsoleEntryPointRunsInsideTheBootedKernel(): void
    {
        // The host calls this for migrations on first launch. Reusing the booted kernel
        // is the point: a device cannot afford a second bootstrap per command.
        MobileRuntime::boot(new RuntimeTestKernel());

        $boots = RuntimeTestKernel::$boots;

        $output = MobileRuntime::artisan('runtime:probe --loud');

        self::assertStringContainsString('probe ran LOUD', $output);
        self::assertSame($boots, RuntimeTestKernel::$boots, 'The command must not have booted a second kernel.');
    }

    public function testAFailingConsoleCommandComesBackAsTextRatherThanAnException(): void
    {
        // Whatever this returns is echoed straight into the host's output. An exception
        // crossing back into C is an app that disappears rather than a command that failed.
        MobileRuntime::boot(new RuntimeTestKernel());

        $output = MobileRuntime::artisan('runtime:nope');

        self::assertStringContainsString('Console error:', $output);
        self::assertStringContainsString('runtime:nope', $output);
        // Not the "Did you mean …?" question: that waits on a stdin no device has.
        self::assertStringNotContainsString('(yes/no)', $output);
    }

    public function testShutdownClosesTheKernelAndForgetsIt(): void
    {
        // Called from the hosts' persistent_shutdown, as another compiled-in eval. It has
        // to be safe to call when nothing was booted, because a failed boot takes that path.
        MobileRuntime::boot(new RuntimeTestKernel());

        self::assertTrue(MobileRuntime::isBooted());

        MobileRuntime::shutdown();

        self::assertFalse(MobileRuntime::isBooted());

        MobileRuntime::shutdown();

        self::assertFalse(MobileRuntime::isBooted());
    }

    private function clearCache(): void
    {
        $dir = sys_get_temp_dir().'/native-mobile-test-kernel';

        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }
}

/** A counter that must be reset between requests for the leak test to mean anything. */
class RequestCounter implements \Symfony\Contracts\Service\ResetInterface
{
    public int $count = 0;

    public function increment(): int
    {
        return ++$this->count;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}

/** Stands in for whatever an application schedules through the host's console entry point. */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'runtime:probe')]
final class ProbeCommand extends \Symfony\Component\Console\Command\Command
{
    protected function configure(): void
    {
        $this->addOption('loud', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE);
    }

    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output,
    ): int {
        $output->writeln('probe ran '.($input->getOption('loud') ? 'LOUD' : 'quiet'));

        return self::SUCCESS;
    }
}

final class RuntimeTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static int $boots = 0;

    public function __construct()
    {
        // debug: false on purpose. Symfony's Debug component installs global error
        // and exception handlers and never removes them, which PHPUnit reports as
        // risky — and a mobile app runs in prod anyway.
        parent::__construct('test', false);
    }

    public function boot(): void
    {
        if (!$this->booted) {
            ++self::$boots;
        }

        parent::boot();
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/native-mobile-test-kernel/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/native-mobile-test-kernel/log';
    }

    protected function configureContainer(\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => false],
        ]);

        $services = $container->services();
        $services->set(RequestCounter::class)->public()->tag('kernel.reset', ['method' => 'reset']);
        $services->set(ProbeCommand::class)->tag('console.command');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('ping', '/ping')->controller([$this, 'ping']);
        $routes->add('counter', '/counter')->controller([$this, 'counter']);
        $routes->add('boom', '/boom')->controller([$this, 'boom']);
        $routes->add('set_cookie', '/set-cookie')->controller([$this, 'setCookie']);
    }

    public function ping(): Response
    {
        return new Response('pong');
    }

    public function counter(): Response
    {
        /** @var RequestCounter $counter */
        $counter = $this->getContainer()->get(RequestCounter::class);

        return new Response((string) $counter->increment());
    }

    public function boom(): Response
    {
        throw new \RuntimeException('deliberate failure');
    }

    public function setCookie(): Response
    {
        $response = new Response('ok');
        $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('visited', 'yes'));

        return $response;
    }
}
