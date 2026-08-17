<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Event\NativeEvent;
use Native\Symfony\Desktop\Event\Windows\WindowResized;
use Native\Symfony\Desktop\EventBridge\EventFactory;
use Native\Symfony\Desktop\Http\EventsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

final class EventsControllerTest extends TestCase
{
    public function testTypedEventsDispatchUnderTheirClassName(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(WindowResized::class, function (WindowResized $e) use (&$seen): void {
            $seen[] = "{$e->id}:{$e->width}x{$e->height}";
        });

        $response = $this->controller($dispatcher)($this->push(
            'Native\Desktop\Events\Windows\WindowResized',
            ['main', 800, 600],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['main:800x600'], $seen);
    }

    public function testCallerNamedEventsReachBothATargetedAndACatchAllListener(): void
    {
        $dispatcher = new EventDispatcher();
        $targeted = [];
        $catchAll = [];

        $dispatcher->addListener('native.App\Menu\Hello', function (NativeEvent $e) use (&$targeted): void {
            $targeted[] = $e->name;
        });
        $dispatcher->addListener(NativeEvent::class, function (NativeEvent $e) use (&$catchAll): void {
            $catchAll[] = $e->name;
        });

        $this->controller($dispatcher)($this->push('App\Menu\Hello', ['combo' => []]));

        self::assertSame(['App\Menu\Hello'], $targeted);
        self::assertSame(['App\Menu\Hello'], $catchAll);
    }

    public function testAMissingEventNameIsRejected(): void
    {
        $response = $this->controller(new EventDispatcher())($this->request('{"payload":[]}'));

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAScalarPayloadIsNormalisedRatherThanFatal(): void
    {
        // Not something the runtime sends today, but the argument spread would
        // fatal on a scalar, so the shape is normalised instead of trusted.
        $dispatcher = new EventDispatcher();
        $seen = null;
        $dispatcher->addListener(NativeEvent::class, function (NativeEvent $e) use (&$seen): void {
            $seen = $e->payload;
        });

        $this->controller($dispatcher)($this->request('{"event":"App\\\\Thing","payload":"scalar"}'));

        self::assertSame(['scalar'], $seen);
    }

    public function testGarbageBodyIsRejectedNotFatal(): void
    {
        $response = $this->controller(new EventDispatcher())($this->request('not json at all'));

        self::assertSame(400, $response->getStatusCode());
    }

    private function controller(EventDispatcher $dispatcher): EventsController
    {
        return new EventsController(new EventFactory(), $dispatcher);
    }

    /** @param array<array-key, mixed> $payload */
    private function push(string $event, array $payload): Request
    {
        return $this->request((string) json_encode(['event' => $event, 'payload' => $payload]));
    }

    private function request(string $body): Request
    {
        return Request::create('/_native/api/events', 'POST', content: $body);
    }
}
