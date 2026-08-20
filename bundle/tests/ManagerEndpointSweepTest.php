<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Support\Platform;
use Native\Symfony\Desktop\Window\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Every manager method, called once, with the endpoint it reaches recorded.
 *
 * The managers are the bundle's public surface and mostly one line each: take an
 * argument, post it somewhere. Two checks already guard parts of that — `ContractCoverage`
 * says every endpoint is called by something, `PayloadKeyContract` says every key sent is
 * one the runtime reads — and neither can see the mistake that actually happens in code
 * shaped like this: a method reaching *another method's* endpoint. `maximize()` posting to
 * `window/minimize` satisfies both checks perfectly. The window minimises, nothing errors,
 * and the only report is a person saying the button does the wrong thing.
 *
 * So: drive every public method, record what the transport saw, and require each to land
 * somewhere of its own.
 */
final class ManagerEndpointSweepTest extends TestCase
{
    /**
     * Methods that legitimately share an endpoint with another, and why.
     *
     * @var array<string, string>
     */
    private const SHARED = [
        // A getter and its shorthand read the same thing.
        'Screen\ScreenManager::primary' => 'screen/primary',
        'Screen\ScreenManager::all' => 'screen/displays',
        // console() is php() with bin/console prepended — one endpoint, two spellings.
        'Process\ChildProcessManager::console' => 'child-process/start-php',
    ];

    /** @param class-string $class */
    #[DataProvider('managers')]
    public function testEveryMethodLandsOnAnEndpointOfItsOwn(string $class): void
    {
        $reached = [];

        foreach ($this->methodsOf($class) as $method) {
            $client = new FakeClient();
            $manager = $this->instantiate($class, $client);
            $arguments = $this->argumentsFor($method);

            if (null === $arguments) {
                continue;
            }

            try {
                $method->invokeArgs($manager, $arguments);
            } catch (\Throwable) {
                // A method may fail on the canned empty reply — decoding a Window out of
                // nothing, say. What it asked the runtime for was decided before that.
            }

            $calls = array_map(
                static fn (array $call): string => $call['method'].' '.$call['endpoint'],
                $client->calls,
            );

            if ([] === $calls) {
                // Not every public method talks to the runtime: some return a builder.
                continue;
            }

            $reached[$method->getName()] = $calls;
        }

        // One is legitimate for a manager whose other methods return a builder —
        // NotificationManager::create() is a builder, send() is the only call. The floor
        // that means something is the total across all of them, asserted below.
        self::assertNotSame([], $reached, sprintf('%s reached the runtime from no method at all.', $class));

        $this->assertNoTwoMethodsShareAnEndpoint($class, $reached);
    }

    public function testTheSweepDrivesTheWholeSurfaceRatherThanAHandful(): void
    {
        // Stated, not implied. Reflection quietly skips any signature it cannot build
        // arguments for, so without a number here the sweep could shrink to nothing —
        // still green, still reporting sixteen managers checked.
        $total = 0;

        foreach (self::managers() as [$class]) {
            foreach ($this->methodsOf($class) as $method) {
                $client = new FakeClient();
                $arguments = $this->argumentsFor($method);

                if (null === $arguments) {
                    continue;
                }

                try {
                    $method->invokeArgs($this->instantiate($class, $client), $arguments);
                } catch (\Throwable) {
                }

                $total += [] === $client->calls ? 0 : 1;
            }
        }

        self::assertGreaterThanOrEqual(60, $total, sprintf('Only %d manager methods reached the runtime.', $total));
    }

    /**
     * @param array<string, list<string>> $reached
     */
    private function assertNoTwoMethodsShareAnEndpoint(string $class, array $reached): void
    {
        $owners = [];

        foreach ($reached as $name => $calls) {
            if (1 !== \count($calls)) {
                // A method that makes several calls is doing something composite; the
                // single-call methods are the ones where a swap is invisible.
                continue;
            }

            $short = str_replace('Native\Symfony\Desktop\\', '', $class).'::'.$name;

            if (isset(self::SHARED[$short])) {
                continue;
            }

            $owners[$calls[0]][] = $name;
        }

        foreach ($owners as $endpoint => $names) {
            self::assertCount(
                1,
                $names,
                sprintf('%s: %s all reach "%s" and nothing else.', $class, implode(' and ', $names), $endpoint),
            );
        }
    }

    /** @return iterable<string, array{class-string}> */
    public static function managers(): iterable
    {
        foreach (glob(__DIR__.'/../src/*/*Manager.php') ?: [] as $file) {
            $class = 'Native\Symfony\Desktop\\'.basename(\dirname($file)).'\\'.basename($file, '.php');

            if (class_exists($class)) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param class-string $class
     *
     * @return list<\ReflectionMethod>
     */
    private function methodsOf(string $class): array
    {
        $methods = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && !$method->isConstructor()) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /** @param class-string $class */
    private function instantiate(string $class, ClientInterface $client): object
    {
        $arguments = [];

        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            $arguments[] = match (true) {
                ClientInterface::class === $name => $client,
                UrlResolver::class === $name => new UrlResolver(new RequestStack(), 'http://localhost'),
                RequestStack::class === $name => new RequestStack(),
                Platform::class === $name => new Platform('Darwin'),
                'string' === $name => 'value',
                'bool' === $name => false,
                default => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            };
        }

        return new $class(...$arguments);
    }

    /**
     * @return list<mixed>|null Null when the signature needs something this cannot invent
     */
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
                // Object parameters this can build. Menu matters: set() and context()
                // both take one, and they are the pair a swap would hide in.
                \Native\Symfony\Desktop\Menu\Menu::class => \Native\Symfony\Desktop\Menu\Menu::new(),
                'int' => 10 + $seed,
                'float' => 1.5,
                'string' => 'value-'.$seed,
                'bool' => true,
                'array' => [],
                default => null,
            };

            if (null === $value) {
                if ($parameter->isOptional() || $type->allowsNull()) {
                    break;
                }

                return null;
            }

            $arguments[] = $value;
        }

        return $arguments;
    }
}
