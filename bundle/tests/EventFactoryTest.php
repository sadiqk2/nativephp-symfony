<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Event\App\OpenedFromURL;
use Native\Symfony\Event\App\OpenFile;
use Native\Symfony\Event\NativeEvent;
use Native\Symfony\Event\Windows\WindowFocused;
use Native\Symfony\Event\Windows\WindowResized;
use Native\Symfony\EventBridge\EventFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventFactoryTest extends TestCase
{
    public function testListPayloadIsSpreadPositionally(): void
    {
        $event = (new EventFactory())->create(
            'Native\Desktop\Events\Windows\WindowResized',
            ['main', 800, 600],
        );

        self::assertInstanceOf(WindowResized::class, $event);
        self::assertSame('main', $event->id);
        self::assertSame(800, $event->width);
        self::assertSame(600, $event->height);
    }

    public function testSingleElementListPayload(): void
    {
        $event = (new EventFactory())->create('Native\Desktop\Events\Windows\WindowFocused', ['main']);

        self::assertInstanceOf(WindowFocused::class, $event);
        self::assertSame('main', $event->id);
    }

    /**
     * OpenedFromURL is the one event the runtime pushes in both shapes: macOS
     * `open-url` sends a list, the Windows/Linux `second-instance` path sends an
     * object. A single class satisfies both only because PHP unpacks a
     * string-keyed array as named arguments.
     *
     * @param array<array-key, mixed> $payload
     */
    #[DataProvider('openedFromUrlPayloads')]
    public function testStringKeyedPayloadIsSpreadAsNamedArguments(array $payload): void
    {
        $event = (new EventFactory())->create('Native\Desktop\Events\App\OpenedFromURL', $payload);

        self::assertInstanceOf(OpenedFromURL::class, $event);
        self::assertSame('myapp://open/42', $event->url);
    }

    public static function openedFromUrlPayloads(): iterable
    {
        yield 'positional (macOS open-url)' => [['myapp://open/42']];
        yield 'named (second-instance)' => [['url' => 'myapp://open/42']];
    }

    #[DataProvider('backslashVariants')]
    public function testLeadingBackslashesAreNormalised(string $name): void
    {
        $event = (new EventFactory())->create($name, ['/tmp/a.txt']);

        self::assertInstanceOf(OpenFile::class, $event);
        self::assertSame('/tmp/a.txt', $event->path);
    }

    public static function backslashVariants(): iterable
    {
        // index.ts and helper/index.ts escape the leading backslash; window.ts
        // does not. Both forms are on the wire today.
        yield 'bare' => ['Native\Desktop\Events\App\OpenFile'];
        yield 'one leading backslash' => ['\Native\Desktop\Events\App\OpenFile'];
        yield 'several' => ['\\\\Native\Desktop\Events\App\OpenFile'];
    }

    public function testUnknownNameBecomesGenericEvent(): void
    {
        // Caller-named events: global shortcuts, menu items and notification
        // overrides all let the app choose the name. Load-bearing, not a fallback.
        $event = (new EventFactory())->create('App\Native\ShortcutPressed', ['CommandOrControl+K']);

        self::assertInstanceOf(NativeEvent::class, $event);
        self::assertSame('App\Native\ShortcutPressed', $event->name);
        self::assertSame('CommandOrControl+K', $event->get(0));
        self::assertSame('native.App\Native\ShortcutPressed', $event->dispatchName());
    }

    public function testArbitraryClassesAreNotInstantiatedWithoutAnAllowlist(): void
    {
        // Upstream does `class_exists($name) ? new $name(...$payload)` straight
        // from the request body. Here an unlisted class is data, not a target.
        SpyEvent::$constructed = false;

        $event = (new EventFactory())->create(SpyEvent::class, ['boom']);

        self::assertInstanceOf(NativeEvent::class, $event);
        self::assertFalse(SpyEvent::$constructed, 'An unlisted class must never be constructed.');
    }

    public function testAllowlistedNamespaceIsInstantiated(): void
    {
        SpyEvent::$constructed = false;

        $event = (new EventFactory([__NAMESPACE__]))->create(SpyEvent::class, ['boom']);

        self::assertInstanceOf(SpyEvent::class, $event);
        self::assertSame('boom', $event->value);
    }

    public function testPayloadThatDoesNotFitTheClassDegradesInsteadOfThrowing(): void
    {
        // A runtime upgrade that changes a payload shape must not 500 this
        // endpoint: notifyLaravel swallows the response, so the failure would be
        // completely invisible.
        $event = (new EventFactory())->create(
            'Native\Desktop\Events\Windows\WindowResized',
            ['id' => 'main'], // width/height missing
        );

        self::assertInstanceOf(NativeEvent::class, $event);
        self::assertArrayHasKey('__error', $event->payload);
    }

    public function testEveryMappedClassExists(): void
    {
        foreach (EventFactory::knownEvents() as $wireName => $class) {
            self::assertTrue(class_exists($class), "{$wireName} maps to a missing class {$class}");
        }
    }
}

final class SpyEvent
{
    public static bool $constructed = false;

    public function __construct(public readonly string $value)
    {
        self::$constructed = true;
    }
}
