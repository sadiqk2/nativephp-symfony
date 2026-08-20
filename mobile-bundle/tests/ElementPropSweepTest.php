<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every fluent setter on all 37 elements, driven once, checked against the wire node.
 *
 * `UiStyledNodeParityTest` compares whole nodes against upstream's renderer, and
 * `UiWireFormat` pins the format itself — but both work from elements a test built by
 * hand, so a setter nothing calls is a setter nothing checks. There are far more of them
 * than there are hand-written cases.
 *
 * The failure being hunted is the one this shape produces: a setter writing a prop that
 * belongs to another setter. On a device that renders — wrongly, silently, with no error
 * and no way to attach a debugger — which is the argument for catching it here.
 */
final class ElementPropSweepTest extends TestCase
{
    /**
     * Setters whose prop deliberately carries another name, with the reason.
     *
     * @var array<string, string>
     */
    private const RENAMED = [
        // The wire name is not always the authoring name; where it differs the divergence
        // is deliberate and the renderers read the right-hand side.
        'Icon::ios' => 'props.ios',
        'Icon::android' => 'props.android',
    ];

    /** @param class-string<Element> $class */
    #[DataProvider('elements')]
    public function testEverySetterWritesAPropOfItsOwn(string $class): void
    {
        $written = [];

        foreach ($this->settersOf($class) as $method) {
            $element = $this->make($class);

            if (null === $element) {
                self::markTestSkipped($class.' cannot be constructed without arguments this test can invent.');
            }

            $arguments = $this->argumentsFor($method);

            if (null === $arguments) {
                continue;
            }

            $before = $this->propsOf($element);

            try {
                $returned = $method->invokeArgs($element, $arguments);
            } catch (\Throwable) {
                continue;
            }

            self::assertInstanceOf($class, $returned, sprintf('%s::%s() must be chainable.', $class, $method->getName()));

            $after = $this->propsOf($returned);
            $changed = $this->changedKeys($before, $after);

            if ([] === $changed) {
                // A setter that writes nothing onto the node at all — a chainable that
                // only stores something the renderer reads elsewhere.
                continue;
            }

            sort($changed);
            $written[$method->getName()] = $changed;
        }

        $this->assertNoTwoSettersShareAProp($class, $written);
    }

    public function testTheSweepDrivesTheWholeElementSurface(): void
    {
        // Stated, not implied: reflection skips signatures it cannot fill, so a sweep that
        // shrank to a handful of setters would pass every check above.
        $total = 0;

        foreach (self::elements() as [$class]) {
            foreach ($this->settersOf($class) as $method) {
                $element = $this->make($class);
                $arguments = $this->argumentsFor($method);

                if (null === $element || null === $arguments) {
                    continue;
                }

                $before = $this->propsOf($element);

                try {
                    $after = $this->propsOf($method->invokeArgs($element, $arguments));
                } catch (\Throwable) {
                    continue;
                }

                $total += [] === $this->changedKeys($before, $after) ? 0 : 1;
            }
        }

        // Of the 384 chainable methods across the 37 elements, 48 write to the node; the
        // rest are the inherited ones — key(), ref(), onPress(), layout(), style() — whose
        // effect is on identity, callbacks or whole-array assignment rather than a named
        // field. The floor sits just under the real number: it exists to catch the sweep
        // silently shrinking, not to pin the count.
        self::assertGreaterThanOrEqual(40, $total, sprintf('Only %d element setters wrote to the node.', $total));
    }

    /**
     * @param array<string, list<string>> $written
     */
    private function assertNoTwoSettersShareAProp(string $class, array $written): void
    {
        $owners = [];

        foreach ($written as $name => $changed) {
            if (1 !== \count($changed)) {
                continue;
            }

            $short = (new \ReflectionClass($class))->getShortName().'::'.$name;

            if (isset(self::RENAMED[$short])) {
                self::assertSame(self::RENAMED[$short], $changed[0]);

                continue;
            }

            $owners[$changed[0]][] = $name;
        }

        foreach ($owners as $prop => $names) {
            self::assertCount(
                1,
                $names,
                sprintf('%s: %s all write "%s" and nothing else.', $class, implode(' and ', $names), $prop),
            );
        }
    }

    /** @return iterable<string, array{class-string<Element>}> */
    public static function elements(): iterable
    {
        foreach (glob(__DIR__.'/../src/Ui/Elements/*.php') ?: [] as $file) {
            /** @var class-string<Element> $class */
            $class = 'Native\Symfony\Mobile\Ui\Elements\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Element::class)) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param class-string<Element> $class */
    private function make(string $class): ?Element
    {
        $reflection = new \ReflectionClass($class);

        // Elements are built through a static make(); most take an optional argument.
        if ($reflection->hasMethod('make')) {
            $make = $reflection->getMethod('make');

            if ($make->isStatic() && 0 === $make->getNumberOfRequiredParameters()) {
                $element = $make->invoke(null);

                return $element instanceof Element ? $element : null;
            }
        }

        return null;
    }

    /**
     * @param class-string<Element> $class
     *
     * @return list<\ReflectionMethod>
     */
    private function settersOf(string $class): array
    {
        $setters = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($method->isStatic() || !$type instanceof \ReflectionNamedType) {
                continue;
            }

            if (\in_array($type->getName(), ['self', 'static', $class], true)) {
                $setters[] = $method;
            }
        }

        return $setters;
    }

    /**
     * Everything of the wire node a setter can write: props, layout and style.
     *
     * Layout and style are read as well as props, though in practice they stay empty here:
     * elements have no per-property layout setters — the base class takes whole arrays
     * (`layout([...])`, `style([...])`) and the per-key vocabulary comes from the Tailwind
     * subset parser, which has its own parity test against upstream. Reading all three
     * anyway costs nothing and means this keeps working if that ever changes.
     *
     * @return array<string, mixed>
     */
    private function propsOf(Element $element): array
    {
        $node = $element->toArray(new CallbackRegistry());

        $fields = [];

        foreach (['props', 'layout', 'style'] as $section) {
            /** @var array<string, mixed> $values */
            $values = $node[$section] ?? [];

            foreach ($values as $key => $value) {
                // Prefixed, so a failure names the section as well as the key — and so a
                // props key never collides with a layout key of the same name.
                $fields[$section.'.'.$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    private function changedKeys(array $before, array $after): array
    {
        $changed = [];

        foreach ($after as $key => $value) {
            if (!\array_key_exists($key, $before) || $before[$key] !== $value) {
                $changed[] = $key;
            }
        }

        return $changed;
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
                'int' => 10 + $seed,
                'float' => 1.5,
                'string' => 'value-'.$method->getName().'-'.$seed,
                'bool' => true,
                'array' => [],
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
