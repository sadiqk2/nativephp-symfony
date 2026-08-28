<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Api;
use Native\Symfony\Mobile\Bridge\Bridge;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Both bridges, the same payloads, one loop: whatever the real one refuses the
     * fake has to refuse too, or a green test certifies a call that throws on a
     * device.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('unsendablePayloads')]
    public function testAPayloadTheRealBridgeCannotSendIsRefusedByTheFakeToo(array $payload, string $reason): void
    {
        foreach (['raw', 'call', 'dispatch'] as $operation) {
            $fake = new FakeBridge();
            $bridges = [
                Bridge::class => new Bridge(invoker: static fn (): string => '{"ok":true}'),
                FakeBridge::class => $fake,
            ];

            foreach ($bridges as $name => $bridge) {
                try {
                    $bridge->{$operation}('Dialog.Toast', $payload);
                    self::fail(sprintf('%s::%s() accepted a payload that cannot be JSON-encoded.', $name, $operation));
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('not JSON-encodable', $e->getMessage());
                    self::assertStringContainsString($reason, $e->getMessage());
                }
            }

            self::assertSame([], $fake->calls, 'The call never reached the native side, so it is not a recorded call.');
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unsendablePayloads(): iterable
    {
        // How an app actually reaches this: a filename, a scanned barcode or a
        // database column that is not UTF-8.
        yield 'a string that is not UTF-8' => [['message' => "Fichier enregistr\xE9"], 'Malformed UTF-8'];

        yield 'a value no JSON type covers' => [['message' => \INF], 'Inf and NaN'];

        yield 'more nesting than the encoder allows' => [['message' => self::nested(600)], 'Maximum stack depth'];
    }

    /** @return array<string, mixed>|string */
    private static function nested(int $depth): array|string
    {
        $value = 'leaf';

        for ($i = 0; $i < $depth; ++$i) {
            $value = ['child' => $value];
        }

        return $value;
    }

    /**
     * The other direction of the same seam: a reply the native side really can
     * give. The real bridge reads all of these as "no answer"; a fake that read
     * them as data answered with a shape the app can never see.
     */
    #[DataProvider('repliesThatCarryNoData')]
    public function testAReplyTheRealBridgeReadsAsNothingIsNothingToTheFakeToo(?string $reply): void
    {
        $real = new Bridge(invoker: static fn (): ?string => $reply);
        $fake = new FakeBridge();
        $fake->willReturn('Device.GetInfo', $reply);

        self::assertNull($real->call('Device.GetInfo'));
        self::assertNull($fake->call('Device.GetInfo'));

        // Device::info() is `call(...) ?? []`, so the difference is visible: an
        // empty array is falsy where ['value' => null] is not.
        self::assertSame([], (new Api\Device($real))->info());
        self::assertSame([], (new Api\Device($fake))->info());
    }

    /** @return iterable<string, array{string|null}> */
    public static function repliesThatCarryNoData(): iterable
    {
        yield 'nothing at all' => [null];

        yield 'an empty string' => [''];

        yield 'whitespace only' => ["  \n"];

        yield 'not JSON' => ['<not json>'];

        yield 'truncated JSON' => ['{"ok":'];

        yield 'JSON behind a byte order mark' => ["\xEF\xBB\xBF{\"ok\":1}"];

        yield 'a NUL byte' => ["\0"];

        yield 'a string that is not UTF-8' => ["\"termin\xE9\""];

        yield 'more nesting than the decoder allows' => [str_repeat('[', 600).'1'.str_repeat(']', 600)];
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
