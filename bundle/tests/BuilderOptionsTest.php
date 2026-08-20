<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Dialog\PendingOpenDialog;
use Native\Symfony\Desktop\Dialog\PendingSaveDialog;
use Native\Symfony\Desktop\MenuBar\PendingMenuBar;
use Native\Symfony\Desktop\Notification\PendingNotification;
use Native\Symfony\Desktop\Window\PendingWindow;
use Native\Symfony\Desktop\Window\UrlResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every option a fluent builder offers, driven once, through reflection.
 *
 * Between them these four classes expose about ninety setters and the suite called a
 * handful: 141 statements uncovered, nearly all of them one-line assignments. That is
 * dull code with one interesting failure mode — writing the wrong key. `PayloadKeyContract`
 * catches a key the runtime does not read, but two setters that write *each other's* key
 * are both writing keys it reads, so nothing sees it: `alwaysOnTop()` would set
 * `skipTaskbar` and the window would sit behind everything with no error anywhere.
 *
 * So rather than ninety hand-written assertions that would each be copied from the line
 * they are testing, this drives each setter with a value of its own and checks three
 * invariants over the result.
 */
final class BuilderOptionsTest extends TestCase
{
    /**
     * Setters that deliberately write more than their own name, or a differently named
     * key, with the reason. Anything not listed here has to follow the naming rule.
     *
     * @var array<string, string>
     */
    private const DELIBERATE = [
        // width/height pairs, and the frame flag whose name is the inverse of the key
        'size' => 'width+height',
        'minSize' => 'minWidth+minHeight',
        'maxSize' => 'maxWidth+maxHeight',
        'position' => 'x+y',
        'frameless' => 'frame',
        'transparent' => 'transparency',
        'trafficLightPosition' => 'trafficLightPosition',
        'preventLeavingDomain' => 'preventLeaveDomain',
        'preventLeavingPage' => 'preventLeavePage',
        'suppressNewWindows' => 'suppressNewWindows',
        'skipTaskbar' => 'skipTaskbar',
        'hiddenInMissionControl' => 'hiddenInMissionControl',
        'windowButtonVisibility' => 'windowButtonVisibility',
        'autoHideMenuBar' => 'autoHideMenuBar',
        'rememberState' => 'rememberState',
        'showDevTools' => 'showDevTools',
        'zoomFactor' => 'zoomFactor',
        'webPreferences' => 'webPreferences',
        'backgroundColor' => 'backgroundColor',
        'titleBarStyle' => 'titleBarStyle',
        // menu bar
        'showDockIcon' => 'showDockIcon',
        'showOnAllWorkspaces' => 'showOnAllWorkspaces',
        'onlyShowContextMenu' => 'onlyShowContextMenu',
        'windowPosition' => 'windowPosition',
        'contextMenu' => 'contextMenu',
        // notification
        'reply' => 'hasReply+replyPlaceholder',
        'closeButton' => 'closeButtonText',
        'actions' => 'actions',
        'toastXml' => 'toastXml',
        'timeoutType' => 'timeoutType',
        'onEvent' => 'event',
        'reference' => 'reference',
        // dialogs
        'button' => 'buttonLabel',
        'defaultPath' => 'defaultPath',
        'windowReference' => 'windowReference',
        'filter' => 'filters',
        'filters' => 'filters',
        'allowMultiple' => 'properties',
        'showHiddenFiles' => 'properties',
        'createDirectory' => 'properties',
        'promptToCreate' => 'properties',
        'directories' => 'properties',
        'files' => 'properties',
        'properties' => 'properties',
        'treatPackageAsDirectory' => 'properties',
        'dontAddToRecent' => 'properties',
        'showOverwriteConfirmation' => 'properties',
    ];

    /** @param callable(): object $factory */
    #[DataProvider('builders')]
    public function testEverySetterWritesAKeyOfItsOwn(string $label, callable $factory): void
    {
        $written = [];
        /** @var array<string, array<string, string>> $appended */
        $appended = [];

        foreach ($this->setters($factory()) as $name => $method) {
            $builder = $factory();
            $before = $builder->payload();

            $arguments = $this->argumentsFor($method);

            if (null === $arguments) {
                continue;
            }

            $returned = $method->invokeArgs($builder, $arguments);

            self::assertSame($builder, $returned, sprintf('%s::%s() must be chainable.', $label, $name));

            $after = $builder->payload();
            $changed = array_keys(array_diff_key($after, $before) + array_filter(
                $after,
                static fn (mixed $value, string $key): bool => \array_key_exists($key, $before) && $before[$key] !== $value,
                \ARRAY_FILTER_USE_BOTH,
            ));

            self::assertNotSame([], $changed, sprintf('%s::%s() changed nothing in the payload.', $label, $name));

            sort($changed);
            $written[$name] = $changed;

            foreach ($changed as $key) {
                if (\in_array($key, self::ACCUMULATORS, true)) {
                    $appended[$key][$name] = json_encode($after[$key] ?? null);
                }
            }
        }

        self::assertGreaterThan(3, \count($written), sprintf('Almost no setters were exercised on %s.', $label));

        $this->assertNamesMatchKeys($label, $written);
        $this->assertNoTwoSettersShareAKey($label, $written);
        $this->assertAccumulatorsAppendDistinctValues($label, $appended);
    }

    /**
     * A setter named after a payload key has to write that key.
     *
     * @param array<string, list<string>> $written
     */
    private function assertNamesMatchKeys(string $label, array $written): void
    {
        $keys = array_unique(array_merge(...array_values($written)));

        foreach ($written as $name => $changed) {
            if (isset(self::DELIBERATE[$name])) {
                continue;
            }

            if (!\in_array($name, $keys, true)) {
                // No key by that name exists at all, so there is nothing to cross with.
                continue;
            }

            self::assertContains(
                $name,
                $changed,
                sprintf('%s::%s() writes [%s] rather than "%s".', $label, $name, implode(', ', $changed), $name),
            );
        }
    }

    /**
     * Keys that several setters legitimately write, because each one appends a flag to a
     * list rather than owning a field of its own. They get the stricter check below.
     *
     * @var list<string>
     */
    private const ACCUMULATORS = ['properties'];

    /**
     * Every setter that appends to a shared list has to append something different.
     *
     * This is the crossed-assignment check for the dialogs: `directories()` and `files()`
     * both write `properties`, so the question is not which key they touch but whether
     * one of them adds the other's flag — which would open the wrong kind of dialog with
     * nothing to show for it.
     *
     * @param array<string, array<string, string>> $appended
     */
    private function assertAccumulatorsAppendDistinctValues(string $label, array $appended): void
    {
        foreach ($appended as $key => $bySetter) {
            self::assertSame(
                \count($bySetter),
                \count(array_unique(array_values($bySetter))),
                sprintf('%s: two setters put the same value into "%s" — %s.', $label, $key, json_encode($bySetter)),
            );
        }
    }

    /**
     * Two setters writing exactly the same single key is the shape of a crossed
     * assignment — `minimizable()` setting `maximizable`, which no contract check can
     * see because both keys are ones the runtime reads.
     *
     * @param array<string, list<string>> $written
     */
    private function assertNoTwoSettersShareAKey(string $label, array $written): void
    {
        $owners = [];

        foreach ($written as $name => $changed) {
            if (1 !== \count($changed) || \in_array($changed[0], self::ACCUMULATORS, true)) {
                continue;
            }

            $owners[$changed[0]][] = $name;
        }

        foreach ($owners as $key => $names) {
            self::assertCount(
                1,
                $names,
                sprintf('%s: %s all write "%s" and nothing else.', $label, implode(' and ', $names), $key),
            );
        }
    }

    /** @return iterable<string, array{string, callable(): object}> */
    public static function builders(): iterable
    {
        $client = static fn (): FakeClient => new FakeClient();
        $urls = static fn (): UrlResolver => new UrlResolver(new RequestStack(), 'http://localhost');

        yield 'PendingWindow' => ['PendingWindow', static fn (): object => new PendingWindow($client(), $urls(), 'main')];
        yield 'PendingMenuBar' => ['PendingMenuBar', static fn (): object => new PendingMenuBar($client(), $urls())];
        yield 'PendingNotification' => ['PendingNotification', static fn (): object => new PendingNotification($client())];
        yield 'PendingOpenDialog' => ['PendingOpenDialog', static fn (): object => new PendingOpenDialog($client())];
        yield 'PendingSaveDialog' => ['PendingSaveDialog', static fn (): object => new PendingSaveDialog($client())];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, \ReflectionMethod> */
    private function setters(object $builder): array
    {
        $setters = [];

        foreach ((new \ReflectionClass($builder))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($method->isStatic() || !$type instanceof \ReflectionNamedType) {
                continue;
            }

            // Chainable by return type: `self` or `static`. Anything else — show(),
            // open(), payload() — either talks to the runtime or reads state.
            if (\in_array($type->getName(), ['self', 'static'], true)) {
                $setters[$method->getName()] = $method;
            }
        }

        return $setters;
    }

    /**
     * Array parameters with a shape of their own — a generic ['key' => 'value'] is the
     * wrong shape for these and blows up inside the setter rather than testing it.
     *
     * @var array<string, list<mixed>>
     */
    private const SHAPED_ARGUMENTS = [
        'filters' => [['Images' => ['png', 'jpg']]],
        'actions' => [['Reply', 'Archive']],
        'webPreferences' => [['nodeIntegration' => false]],
    ];

    /**
     * Setters whose parameters are objects the reflection cannot invent. Listed rather
     * than skipped: `contextMenu()` and `properties()` carry real payload keys, and a
     * skipped setter is an untested setter.
     *
     * @return array<string, list<mixed>>
     */
    private static function objectArguments(): array
    {
        return [
            'contextMenu' => [\Native\Symfony\Desktop\Menu\Menu::new()],
            // NoResolveAliases on purpose: it is the one property with no named toggle of
            // its own, so the generic setter cannot collide with a specific one and mask
            // a real duplicate.
            'properties' => [\Native\Symfony\Desktop\Enums\DialogProperty::NoResolveAliases],
        ];
    }

    /**
     * A distinct, type-appropriate value per parameter, or null when the signature needs
     * something this cannot invent — an object, a union, a variadic.
     *
     * @return list<mixed>|null
     */
    private function argumentsFor(\ReflectionMethod $method): ?array
    {
        if (isset(self::SHAPED_ARGUMENTS[$method->getName()])) {
            return self::SHAPED_ARGUMENTS[$method->getName()];
        }

        if (isset(self::objectArguments()[$method->getName()])) {
            return self::objectArguments()[$method->getName()];
        }

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
                'float' => 1.5 + $seed,
                'string' => 'value-'.$method->getName().'-'.$seed,
                'bool' => true,
                'array' => ['key-'.$seed => 'value'],
                default => null,
            };

            if (null === $value) {
                if ($parameter->isOptional()) {
                    // A trailing object parameter with a default: drive the rest.
                    break;
                }

                return null;
            }

            $arguments[] = $value;
        }

        return $arguments;
    }
}
