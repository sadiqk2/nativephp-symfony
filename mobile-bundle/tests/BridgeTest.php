<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Api;
use Native\Symfony\Mobile\Bridge\Bridge;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class BridgeTest extends TestCase
{
    public function testTheRealBridgeIsUnavailableOutsideAPackagedApp(): void
    {
        // nativephp_call() is a compiled extension function that exists only inside
        // a NativePHP app. Everything else in this suite depends on the fake.
        self::assertFalse((new Bridge())->isAvailable());
        self::assertNull((new Bridge())->raw('Device.GetInfo'));
        self::assertNull((new Bridge())->call('Device.GetInfo'));
        self::assertFalse((new Bridge())->dispatch('Dialog.Toast', ['message' => 'hi']));
    }

    public function testANonJsonEncodablePayloadFailsLoudly(): void
    {
        // A silent drop here would look like a native-side failure and send someone
        // debugging the wrong layer.
        $bridge = new Bridge(invoker: static fn (): ?string => null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not JSON-encodable/');

        $bridge->raw('X.Y', ['resource' => fopen('php://memory', 'r')]);
    }

    public function testNonJsonRepliesAreLoggedRatherThanSwallowed(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        $bridge = new Bridge($logger, static fn (): string => '<not json>');

        self::assertNull($bridge->call('Device.GetInfo'));
        self::assertSame(['error'], $logger->levels);
    }

    public function testTheRealBridgePassesMethodAndJsonToTheExtension(): void
    {
        $seen = [];
        $bridge = new Bridge(invoker: static function (string $method, string $json) use (&$seen): string {
            $seen = [$method, $json];

            return '{"ok":true}';
        });

        self::assertSame(['ok' => true], $bridge->call('Device.GetInfo', ['a' => 1]));
        self::assertSame('Device.GetInfo', $seen[0]);
        self::assertSame('{"a":1}', $seen[1]);
    }

    public function testAThrowFromTheExtensionBecomesNullAndIsLogged(): void
    {
        // Most of these are optional capabilities; an exception escaping into a
        // controller is usually worse than a null the caller can handle.
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };

        $bridge = new Bridge($logger, static fn (): string => throw new \RuntimeException('native boom'));

        self::assertNull($bridge->raw('Device.GetInfo'));
        self::assertSame(['error'], $logger->levels);
    }

    public function testUnicodeAndSlashesSurviveEncoding(): void
    {
        // JSON_UNESCAPED_* matters here: a path or a non-ASCII label mangled on the
        // way to the native side is very hard to spot on a device.
        $seen = '';
        $bridge = new Bridge(invoker: static function (string $method, string $json) use (&$seen): ?string {
            $seen = $json;

            return null;
        });

        $bridge->raw('Share.File', ['path' => '/a/b/c.pdf', 'title' => 'Café — naïve']);

        self::assertSame('{"path":"/a/b/c.pdf","title":"Café — naïve"}', $seen);
    }

    public function testAScalarJsonReplyIsWrappedRatherThanDiscarded(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('Thing.Get', '"a string"');

        self::assertSame(['value' => 'a string'], $bridge->call('Thing.Get'));
    }

    public function testFakeBridgeRecordsMethodAndPayload(): void
    {
        $bridge = new FakeBridge();

        (new Api\Dialog($bridge))->toast('Saved', 'short');

        self::assertSame('Dialog.Toast', $bridge->lastCall()['method']);
        self::assertSame(['message' => 'Saved', 'duration' => 'short'], $bridge->lastCall()['payload']);
    }

    public function testAnUnavailableFakeMakesEveryDispatchReportFailure(): void
    {
        $bridge = new FakeBridge(available: false);

        self::assertFalse((new Api\Dialog($bridge))->toast('x'));
        self::assertNull((new Api\SecureStorage($bridge))->get('token'));
    }
}
