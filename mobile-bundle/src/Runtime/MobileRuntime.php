<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Runtime;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * A long-lived kernel that serves many requests in one PHP process.
 *
 * This is where mobile's speed comes from. The one-shot path pays for the
 * autoloader, the container and every service provider on each request; on a
 * phone that is the difference between a native-feeling app and a sluggish one.
 * The native layer boots this once and then dispatches through it.
 *
 * Laravel's equivalent (Native\Mobile\Runtime) hand-rolls state resetting:
 * clearing resolved facades, poking the router, flushing Livewire. Symfony has
 * `services_resetter` for precisely this — it is what `messenger:consume` uses
 * between messages — so anything tagged `kernel.reset` is handled properly,
 * including bundles this code has never heard of. That is a genuine advantage of
 * the port rather than a translation of it.
 */
final class MobileRuntime
{
    private static ?self $instance = null;

    private int $dispatched = 0;

    private function __construct(
        private readonly KernelInterface $kernel,
        private readonly ResponseEmitter $emitter,
        private readonly bool $collectGarbage = false,
    ) {
    }

    /**
     * Boot once. Returns the instance so a bootstrap script can hold it, and
     * stores it statically because subsequent dispatches arrive through a fresh
     * eval'd scope with no reference to it.
     */
    public static function boot(
        KernelInterface $kernel,
        ?ResponseEmitter $emitter = null,
        bool $collectGarbage = false,
    ): self {
        $kernel->boot();

        return self::$instance = new self($kernel, $emitter ?? new ResponseEmitter(), $collectGarbage);
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            throw new \LogicException(
                'The mobile runtime has not been booted. The native layer must execute '.
                'the persistent bootstrap script before dispatching a request.',
            );
        }

        return self::$instance;
    }

    public static function isBooted(): bool
    {
        return null !== self::$instance;
    }

    /** Only for tests — a real process boots once and stays booted. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Handle one request. Never throws: a fatal here would take down the whole
     * app rather than one screen, and the native layer has no way to recover.
     */
    public function handle(Request $request): Response
    {
        ++$this->dispatched;

        try {
            $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        } catch (\Throwable $e) {
            $response = $this->errorResponse($e);
        }

        try {
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }
        } catch (\Throwable) {
            // Terminate runs after the response is settled; a failure there must
            // not replace a good response with an error page.
        }

        $this->resetServices();

        if ($this->collectGarbage) {
            gc_collect_cycles();
        }

        return $response;
    }

    /** Handle a request and write it to stdout in the form the native layer parses. */
    public function emit(Request $request, array $extraHeaders = []): void
    {
        $this->emitter->emit($this->handle($request), $extraHeaders);
    }

    /**
     * The entry point both hosts compile into their own per-request preamble.
     *
     * Android's php_bridge.c and iOS's PHP.c build the dispatch as a C string literal
     * and hand it to zend_eval_string: `$__response = \Native\Mobile\Runtime::dispatch(
     * \Illuminate\Http\Request::capture());`, then echo the status line, the headers
     * and the body themselves. That is compiled into the app, not read from a file, so
     * retargeting the bootstrap scripts — which is all the patcher used to do — left
     * every request in persistent mode calling a Laravel class that a Symfony app does
     * not have. {@see MobileRuntimePatcher} rewrites those literals onto this method,
     * which is why it is static, takes a Request and returns a Response rather than
     * writing one: the host writes the message itself.
     */
    public static function dispatch(Request $request): Response
    {
        return self::instance()->handle($request);
    }

    /**
     * Run a console command inside the booted kernel and return its output.
     *
     * The hosts call this for migrations on first launch and for anything an app
     * schedules; upstream's equivalent is `Runtime::artisan()`. Reusing the booted
     * kernel is the point — a device cannot afford a second bootstrap — so the
     * application is built here rather than by the console shim, which is a separate
     * process with a separate kernel.
     */
    public static function artisan(string $command): string
    {
        $kernel = self::instance()->kernel;

        if (!class_exists(Application::class)) {
            throw new \LogicException(
                'Running a console command through the persistent runtime needs '.
                'symfony/framework-bundle, which this application does not have installed.',
            );
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new BufferedOutput();

        try {
            $application->run(new StringInput($command), $output);
        } catch (\Throwable $e) {
            error_log('[NATIVE_EXCEPTION]: console command "'.$command.'" failed — '.$e->getMessage());

            return $output->fetch().'Console error: '.$e->getMessage()."\n";
        }

        return $output->fetch();
    }

    /**
     * Shut the kernel down, because the host is about to tear the interpreter down.
     *
     * Called from the hosts' `persistent_shutdown`, again as a compiled-in eval.
     * Skipping it would leave whatever a bundle registered on shutdown unrun —
     * Doctrine connections, buffered logs — on every app exit.
     */
    public static function shutdown(): void
    {
        if (null === self::$instance) {
            return;
        }

        try {
            self::$instance->kernel->shutdown();
        } catch (\Throwable $e) {
            error_log('[NATIVE_EXCEPTION]: kernel shutdown failed — '.$e->getMessage());
        } finally {
            self::$instance = null;
        }
    }

    public function dispatchCount(): int
    {
        return $this->dispatched;
    }

    /**
     * Reset per-request state between dispatches.
     *
     * `services_resetter` is registered by FrameworkBundle and resets every
     * service tagged `kernel.reset` — Doctrine's entity manager, the profiler, the
     * validator, security token storage, and anything a third-party bundle tagged.
     * Without this, state leaks between screens in ways that are very hard to
     * diagnose on a device.
     */
    private function resetServices(): void
    {
        $container = $this->kernel->getContainer();

        if (!$container->has('services_resetter')) {
            return;
        }

        try {
            $resetter = $container->get('services_resetter');

            if (\is_object($resetter) && method_exists($resetter, 'reset')) {
                $resetter->reset();
            }
        } catch (\Throwable) {
            // A resetter that throws must not kill the app; the next request may
            // see stale state, which is strictly better than a crash.
        }
    }

    private function errorResponse(\Throwable $e): Response
    {
        // Goes to logcat on Android and the Xcode console on iOS — the only place
        // a developer can see it, since there is no log tail on a device.
        error_log(sprintf(
            '[NATIVE_EXCEPTION]: %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        $debug = filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOLEAN);

        $body = $debug
            ? sprintf("%s: %s\nin %s:%d\n\n%s", $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString())
            : 'Application error.';

        return new Response($body, 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
