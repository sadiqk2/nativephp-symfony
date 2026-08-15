<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Security\RuntimeRoutesAccessMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * The decorator that keeps an application's access_control off the runtime's two
 * endpoints.
 *
 * It hands away the app's own protection, so the interesting tests are all about
 * when it must *not*. Both of the first two cases were live holes: the exemption
 * originally keyed off `running` alone, and matched the shared path prefix rather
 * than the two known paths.
 */
final class RuntimeRoutesAccessMapTest extends TestCase
{
    private const ATTRIBUTES = ['IS_AUTHENTICATED_FULLY'];

    public function testItExemptsTheRuntimeEndpointsInsideTheRuntime(): void
    {
        $map = $this->map(running: true);

        self::assertSame([null, null], $map->getPatterns(Request::create('/_native/api/booted', 'POST')));
        self::assertSame([null, null], $map->getPatterns(Request::create('/_native/api/events', 'POST')));
    }

    public function testItExemptsNothingOutsideTheRuntime(): void
    {
        // The same codebase served on the web: these paths dispatch events by name,
        // and there the app's firewall is the only thing in front of them.
        $map = $this->map(running: false);

        self::assertSame([self::ATTRIBUTES, null], $map->getPatterns(Request::create('/_native/api/booted', 'POST')));
    }

    public function testItExemptsNothingWhenTheSecretGateIsTurnedOff(): void
    {
        // `block_browser_access: false` is one documented line. With the gate gone
        // there is nothing enforcing in place of the rules being handed away, so
        // both endpoints would be open to any local process.
        $map = $this->map(running: true, gateEnabled: false);

        self::assertSame([self::ATTRIBUTES, null], $map->getPatterns(Request::create('/_native/api/booted', 'POST')));
    }

    public function testItExemptsNothingWhenTheRuntimeSuppliedNoSecret(): void
    {
        // The gate deliberately fails open without a secret, so that a
        // misconfiguration is diagnosable rather than a wall of 403s. That was
        // survivable only while the app's own rules were still the backstop.
        foreach ([null, ''] as $secret) {
            $map = $this->map(running: true, secret: $secret);

            self::assertSame(
                [self::ATTRIBUTES, null],
                $map->getPatterns(Request::create('/_native/api/booted', 'POST')),
                'A gate that is failing open must not also take the firewall with it.',
            );
        }
    }

    public function testAnApplicationRouteUnderTheSamePrefixKeepsItsRules(): void
    {
        // A prefix match also covers whatever else the app routes under it. The
        // shape that makes this bite without anyone choosing it is the SPA
        // catch-all — `/{path}` with a `.*` requirement — which would lose its
        // access_control for that whole subtree inside the desktop app.
        $map = $this->map(running: true);

        foreach (['/_native/api/admin', '/_native/api/', '/_native/api/dashboard'] as $path) {
            self::assertSame(
                [self::ATTRIBUTES, null],
                $map->getPatterns(Request::create($path)),
                sprintf('%s is not one of the bundle\'s own routes.', $path),
            );
        }
    }

    private function map(
        bool $running,
        bool $gateEnabled = true,
        ?string $secret = 'abcdefghijklmnopqrstuvwxyz012345',
    ): RuntimeRoutesAccessMap {
        $inner = new class implements AccessMapInterface {
            public function getPatterns(Request $request): array
            {
                return [RuntimeRoutesAccessMapTest::attributes(), null];
            }
        };

        return new RuntimeRoutesAccessMap($inner, $running, $gateEnabled, $secret);
    }

    /** @return list<string> */
    public static function attributes(): array
    {
        return self::ATTRIBUTES;
    }
}
