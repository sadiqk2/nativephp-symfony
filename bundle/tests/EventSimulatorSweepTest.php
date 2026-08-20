<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Event\NativeEvent;
use Native\Symfony\Desktop\Testing\RuntimeEventSimulator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Every simulator shorthand, driven once, checked for the fallback.
 *
 * These wrappers are what an application's own test suite uses to stand in for the
 * runtime, so a mistake here is worse than a mistake in the bundle: it makes somebody
 * else's green suite meaningless. And the mistake this code invites is silent. The
 * factory answers an unknown or misspelled event name with a generic {@see NativeEvent}
 * rather than failing, so a typo in one of these class names produces a dispatch that
 * *looks* fine — the listener for the typed event simply never runs, and the app author
 * concludes the feature is broken rather than the double.
 *
 * That has already happened once in this project: every AutoUpdater event degraded to
 * NativeEvent because the payload keys did not survive JSON encoding, and nothing caught
 * it because a degraded event is still an event.
 */
final class EventSimulatorSweepTest extends TestCase
{
    /**
     * Shorthands that correctly produce the generic event, with the reason.
     *
     * @var array<string, string>
     */
    private const GENERIC = [
        // The application chooses these names itself — there is no class to resolve.
        'shortcutPressed' => 'the app names its own shortcut events',
        'notificationActionClicked' => 'the app names its own notification actions',
    ];

    /**
     * Shorthands whose name deliberately differs from the runtime's, and what it maps to.
     *
     * @var array<string, string>
     */
    private const RENAMED = [
        // The runtime calls it App\OpenFile; "fileOpened" reads as what happened, and
        // matches the tense of every other shorthand here.
        'fileOpened' => 'OpenFile',
        // Likewise Notifications\NotificationReply, which is the noun rather than the verb.
        'notificationReplied' => 'NotificationReply',
    ];

    public function testNoShorthandSilentlyProducesTheGenericFallback(): void
    {
        $swept = 0;

        foreach ($this->shorthands() as $method) {
            $recorder = new RecordingDispatcher();
            $simulator = new RuntimeEventSimulator($recorder);

            $arguments = $this->argumentsFor($method);

            if (null === $arguments) {
                continue;
            }

            $method->invokeArgs($simulator, $arguments);
            ++$swept;

            self::assertNotSame([], $recorder->events, sprintf('%s() dispatched nothing at all.', $method->getName()));

            if (isset(self::GENERIC[$method->getName()])) {
                continue;
            }

            foreach ($recorder->events as $event) {
                self::assertNotSame(
                    NativeEvent::class,
                    $event::class,
                    sprintf(
                        '%s() produced the generic fallback, so a listener for its typed event never runs. '.
                        'Either the event class name is wrong or the payload shape does not fit its constructor.',
                        $method->getName(),
                    ),
                );
            }
        }

        // Stated, not implied: reflection skips signatures it cannot fill, and a sweep
        // that shrank to two methods would still pass everything above.
        self::assertGreaterThanOrEqual(15, $swept, sprintf('Only %d shorthands were driven.', $swept));
    }

    public function testTheEventClassMatchesTheShorthandThatSentIt(): void
    {
        // The fallback check catches a name that resolves to nothing. This catches a name
        // that resolves to the wrong thing — windowFocused() dispatching WindowBlurred is
        // a typed event, a real class, and completely wrong.
        foreach ($this->shorthands() as $method) {
            if (isset(self::GENERIC[$method->getName()])) {
                continue;
            }

            $arguments = $this->argumentsFor($method);

            if (null === $arguments) {
                continue;
            }

            $recorder = new RecordingDispatcher();
            (new RuntimeEventSimulator($recorder))->{$method->getName()}(...$arguments);

            $classes = array_map(static fn (object $event): string => (new \ReflectionClass($event))->getShortName(), $recorder->events);

            self::assertNotSame([], $classes);

            if (isset(self::RENAMED[$method->getName()])) {
                self::assertContains(self::RENAMED[$method->getName()], $classes);

                continue;
            }

            // "windowResized" → "WindowResized", "processMessageReceived" → "MessageReceived".
            // Compared on words rather than the whole name, because the shorthands drop the
            // subsystem prefix the event class carries.
            $expected = $this->words($method->getName());
            $matched = false;

            foreach ($classes as $class) {
                $actual = $this->words($class);

                if ([] !== array_intersect($expected, $actual) && \count(array_intersect($expected, $actual)) >= min(2, \count($expected))) {
                    $matched = true;
                }
            }

            self::assertTrue(
                $matched,
                sprintf('%s() dispatched %s, which does not look like the event it names.', $method->getName(), implode(', ', $classes)),
            );
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return list<\ReflectionMethod> */
    private function shorthands(): array
    {
        $methods = [];

        foreach ((new \ReflectionClass(RuntimeEventSimulator::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // dispatch() and make() are the general-purpose entry points; the shorthands
            // are the ones that encode a payload shape and are therefore worth sweeping.
            if (!$method->isConstructor() && !\in_array($method->getName(), ['dispatch', 'make'], true)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /** @return list<string> The lowercased words of a camelCase name */
    private function words(string $name): array
    {
        return array_values(array_filter(array_map(
            'strtolower',
            preg_split('/(?=[A-Z])/', $name) ?: [],
        )));
    }

    /** @return list<mixed>|null */
    private function argumentsFor(\ReflectionMethod $method): ?array
    {
        $arguments = [];
        $seed = 0;

        foreach ($method->getParameters() as $parameter) {
            ++$seed;
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType) {
                return null;
            }

            $value = match ($type->getName()) {
                'int' => 100 + $seed,
                'float' => 1.5,
                'string' => 'value-'.$seed,
                'bool' => false,
                'array' => [],
                'mixed' => 'anything',
                default => null,
            };

            if (null === $value) {
                if ($parameter->isOptional()) {
                    break;
                }

                return null;
            }

            $arguments[] = $value;
        }

        return $arguments;
    }
}

/** Keeps every object dispatched, which is the only observable a simulator has. */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return $event;
    }
}
