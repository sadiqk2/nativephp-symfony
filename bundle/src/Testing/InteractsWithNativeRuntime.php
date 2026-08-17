<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Testing;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\EventBridge\EventFactory;
use Native\Symfony\Desktop\Window\UrlResolver;
use Native\Symfony\Desktop\Window\WindowManager;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Wiring for the three pieces of the kit, in one `use`.
 *
 * Works both ways round, which is the point:
 *
 *  - **In a plain TestCase** it builds its own {@see FakeRuntime}. Managers are
 *    constructed with `new`, no kernel involved.
 *  - **In a KernelTestCase** with `native_desktop.testing: true` it finds the
 *    fake the container already injected everywhere, so services under test —
 *    controllers, subscribers, the app's own classes — are asserted against the
 *    same call log without any of them being handed a double by the test.
 *
 * On why the bundle grew a `testing` flag rather than leaving apps to override
 * the `ClientInterface` alias themselves: an app that overrides it has to know
 * the service id, keep the override out of prod, and repeat it per project, and
 * a mistake there silently produces a test suite that talks to a runtime that is
 * not running (every call throws RuntimeNotAvailable, which reads as an
 * environment problem rather than a wiring one). One boolean in
 * `config/packages/test/native_desktop.yaml` is harder to get wrong and impossible
 * to leak into prod, since Symfony scopes it by environment. The override route
 * still works for anyone who wants it — the flag only re-points an alias.
 */
trait InteractsWithNativeRuntime
{
    private ?FakeRuntime $nativeRuntime = null;
    private ?RequestStack $nativeRequests = null;
    private ?EventDispatcher $nativeDispatcher = null;

    /**
     * The fake transport every native call in this test goes through.
     *
     * Prefers the container's when there is a booted kernel whose ClientInterface
     * is already a fake — two fakes in one test is the failure mode this avoids,
     * because assertions would be made against the one nobody called.
     */
    protected function nativeRuntime(): FakeRuntime
    {
        if (null !== $this->nativeRuntime) {
            return $this->nativeRuntime;
        }

        return $this->nativeRuntime = $this->containerRuntime() ?? FakeRuntime::available();
    }

    /** Assertions about what the app asked the runtime to do. */
    protected function nativeExpects(): RuntimeExpectations
    {
        return new RuntimeExpectations($this->nativeRuntime());
    }

    /**
     * Push runtime events into the app.
     *
     * @param EventDispatcherInterface|null $dispatcher Defaults to the container's
     *                                                  when a kernel is booted, so
     *                                                  the app's real listeners run
     */
    protected function nativeEvents(?EventDispatcherInterface $dispatcher = null): RuntimeEventSimulator
    {
        return new RuntimeEventSimulator(
            $dispatcher ?? $this->containerService(EventDispatcherInterface::class) ?? $this->nativeDispatcher(),
            $this->containerService(EventFactory::class),
        );
    }

    /**
     * A dispatcher owned by the test, for unit-testing a listener in isolation.
     *
     * Register the listener on it, then push an event with nativeEvents().
     */
    protected function nativeDispatcher(): EventDispatcher
    {
        return $this->nativeDispatcher ??= new EventDispatcher();
    }

    /**
     * A WindowManager on the fake — the one manager worth a factory, since it
     * needs a UrlResolver and a RequestStack that a unit test would otherwise
     * have to assemble by hand. Every other manager is `new XManager($this->nativeRuntime())`.
     */
    protected function nativeWindows(?string $baseUrl = 'http://localhost'): WindowManager
    {
        return new WindowManager(
            $this->nativeRuntime(),
            new UrlResolver($this->nativeRequests(), $baseUrl),
            $this->nativeRequests(),
        );
    }

    /**
     * Pretend the request under test came from a native window.
     *
     * The runtime appends `?_windowId=` to everything it navigates, and every
     * manager method that takes an optional id falls back to it. Without this,
     * an id-less call in a test resolves to 'main' and a test cannot tell a
     * correct fallback from a lost id.
     */
    protected function nativeRequestFromWindow(string $windowId, string $uri = '/'): void
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        $this->nativeRequests()->push(Request::create($uri.$separator.'_windowId='.$windowId));
    }

    protected function nativeRequests(): RequestStack
    {
        return $this->nativeRequests ??= new RequestStack();
    }

    private function containerRuntime(): ?FakeRuntime
    {
        $client = $this->containerService(ClientInterface::class);

        return $client instanceof FakeRuntime ? $client : null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T|null
     */
    private function containerService(string $id): ?object
    {
        // No kernel, no container: the plain-TestCase path, which is not an error.
        // getContainer() throws rather than returning null when nothing is booted,
        // and the service may be private, so both are treated as "not available".
        if (!method_exists(static::class, 'getContainer')) {
            return null;
        }

        try {
            $service = static::getContainer()->get($id);
        } catch (\Throwable) {
            return null;
        }

        return $service instanceof $id ? $service : null;
    }
}
