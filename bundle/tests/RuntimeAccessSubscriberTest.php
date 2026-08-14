<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Security\RuntimeAccessSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RuntimeAccessSubscriberTest extends TestCase
{
    private const SECRET = 'abcdefghijklmnopqrstuvwxyz012345';

    public function testRequestWithTheCorrectHeaderPasses(): void
    {
        $request = Request::create('/');
        $request->headers->set(RuntimeAccessSubscriber::HEADER, self::SECRET);

        self::assertNull($this->handle($request)->getResponse());
    }

    public function testRequestWithTheCorrectCookiePasses(): void
    {
        $request = Request::create('/', cookies: [RuntimeAccessSubscriber::COOKIE => self::SECRET]);

        self::assertNull($this->handle($request)->getResponse());
    }

    public function testRequestWithNeitherIsForbidden(): void
    {
        $event = $this->handle(Request::create('/'));

        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    public function testWrongSecretIsForbidden(): void
    {
        $request = Request::create('/');
        $request->headers->set(RuntimeAccessSubscriber::HEADER, 'nope');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->handle($request)->getResponse()?->getStatusCode());
    }

    public function testOutsideTheRuntimeEverythingPasses(): void
    {
        // The same codebase served as an ordinary web app must not 403 itself.
        $event = $this->handle(Request::create('/'), running: false);

        self::assertNull($event->getResponse());
    }

    public function testMissingSecretFailsOpen(): void
    {
        // A runtime that gave us no secret is misconfigured; 403ing every request
        // would leave no way to see or fix that.
        $event = $this->handle(Request::create('/'), secret: null);

        self::assertNull($event->getResponse());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::SUB_REQUEST,
        );

        (new RuntimeAccessSubscriber(true, self::SECRET))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testItRunsBeforeTheFirewall(): void
    {
        $priority = RuntimeAccessSubscriber::getSubscribedEvents()['kernel.request'][1];

        // Symfony's firewall listens at 8; anything above it would run first and
        // could authenticate a request that should never have been served.
        self::assertGreaterThan(8, $priority);
    }

    private function handle(Request $request, bool $running = true, ?string $secret = self::SECRET): RequestEvent
    {
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new RuntimeAccessSubscriber($running, $secret))->onKernelRequest($event);

        return $event;
    }
}
