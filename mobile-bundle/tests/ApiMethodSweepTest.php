<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Bridge\FakeBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every method of the seventeen API classes, called once, with the bridge method recorded.
 *
 * The desktop managers got this sweep; the mobile API is the same shape and carries the
 * same blind spot. `BridgeCoverage` asks whether every native method upstream exposes is
 * called by something, and `BridgePayloadKeyContract` asks whether the keys we send are
 * the ones the hosts read. Neither can see `Share::file()` dispatching `Share.Url`: that
 * is a real method, with real keys, called by something. The share sheet opens, the file
 * is missing from it, and nothing anywhere reports a fault.
 *
 * Mobile has less margin for this than desktop, because there is no device here to catch
 * it later: a crossed method name would ship.
 */
final class ApiMethodSweepTest extends TestCase
{
    /**
     * Methods that legitimately share a native method with another, and why.
     *
     * @var array<string, string>
     */
    private const SHARED = [
        // Convenience readings of one native call, not separate capabilities. Each of
        // these delegates to the method above it and interprets the reply, which is a
        // deliberate saving: a native round trip on a device is not free.
        'Network::isConnected' => 'Network.Status',
        'Network::connectionType' => 'Network.Status',
        'Microphone::isRecording' => 'Microphone.GetStatus',
        'PushNotifications::isAuthorised' => 'PushNotification.CheckPermission',
        'SecureStorage::has' => 'SecureStorage.Get',
        'System::isDarkMode' => 'System.GetAppearance',
    ];

    /** @param class-string $class */
    #[DataProvider('apis')]
    public function testEveryMethodReachesANativeMethodOfItsOwn(string $class): void
    {
        $reached = [];

        foreach ($this->methodsOf($class) as $method) {
            $bridge = new FakeBridge();
            $api = new $class($bridge);
            $arguments = $this->argumentsFor($method);

            if (null === $arguments) {
                continue;
            }

            try {
                $method->invokeArgs($api, $arguments);
            } catch (\Throwable) {
                // A method may fail decoding the canned empty reply. What it asked the
                // native layer for was decided before that.
            }

            $names = array_map(static fn (array $call): string => $call['method'], $bridge->calls);

            if ([] === $names) {
                continue;
            }

            $reached[$method->getName()] = $names;
        }

        self::assertNotSame([], $reached, sprintf('%s reached the bridge from no method at all.', $class));

        $owners = [];

        foreach ($reached as $name => $names) {
            if (1 !== \count($names)) {
                continue;
            }

            $short = str_replace('Native\Symfony\Mobile\Api\\', '', $class).'::'.$name;

            if (isset(self::SHARED[$short])) {
                continue;
            }

            $owners[$names[0]][] = $name;
        }

        foreach ($owners as $native => $names) {
            self::assertCount(
                1,
                $names,
                sprintf('%s: %s all reach "%s" and nothing else.', $class, implode(' and ', $names), $native),
            );
        }
    }

    public function testTheSweepDrivesTheWholeApiRatherThanAHandful(): void
    {
        // Stated, not implied. Reflection skips any signature it cannot fill, so without a
        // number the sweep could quietly shrink to a few methods and stay green.
        $total = 0;

        foreach (self::apis() as [$class]) {
            foreach ($this->methodsOf($class) as $method) {
                $bridge = new FakeBridge();
                $arguments = $this->argumentsFor($method);

                if (null === $arguments) {
                    continue;
                }

                try {
                    $method->invokeArgs(new $class($bridge), $arguments);
                } catch (\Throwable) {
                }

                $total += [] === $bridge->calls ? 0 : 1;
            }
        }

        self::assertGreaterThanOrEqual(40, $total, sprintf('Only %d API methods reached the bridge.', $total));
    }

    /** @return iterable<string, array{class-string}> */
    public static function apis(): iterable
    {
        foreach (glob(__DIR__.'/../src/Api/*.php') ?: [] as $file) {
            $class = 'Native\Symfony\Mobile\Api\\'.basename($file, '.php');

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
                'string' => 'value-'.$seed,
                'bool' => true,
                'array' => [],
                'mixed' => 'anything',
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
