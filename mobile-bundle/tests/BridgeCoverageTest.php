<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the bridge surface against upstream drift, the way the desktop bundle's
 * ContractCoverageTest guards the HTTP API: parse the methods the upstream PHP
 * actually calls, and assert this package wraps every one.
 *
 * Skips when the upstream sources are not checked out, so the pinned count below
 * is what catches an accidental deletion here.
 */
final class BridgeCoverageTest extends TestCase
{
    use ParsesNativeCalls;

    private const int EXPECTED_METHODS = 62;

    /**
     * Upstream methods with no wrapper here, named rather than left to a count that
     * happens to agree.
     *
     * This list has now been wrong twice for the same reason, and both times the parser
     * was the cause rather than the port. It first held Dialog.Alert, Scanner.Scan and
     * PushNotification.RequestPermission, called as `nativephp_call(\n    'Method',` with
     * the argument on its own line: the parser required the quote immediately after the
     * parenthesis, so none was discovered, the count read 54, and a test called "every
     * upstream bridge method is wrapped" passed while three were not. Those three now
     * have wrappers.
     *
     * The five below were invisible one step further on: upstream computes their names
     * above the call site rather than writing them at it, so the count read 57. Every one
     * of them starts or locates a position, which `Api\Geolocation` does not do at all —
     * it addresses a watch that something else started — so they are stated as gaps
     * rather than wrapped to keep this list short.
     */
    private const array UNWRAPPED = [
        'Geolocation.CheckPermissions',
        'Geolocation.GetCurrentPosition',
        'Geolocation.RequestPermissions',
        'Geolocation.StartBackgroundWatch',
        'Geolocation.WatchPosition',
    ];

    public function testTheOnlyUnwrappedBridgeMethodsAreTheOnesNamedHere(): void
    {
        $upstream = $this->upstreamMethods();

        if ([] === $upstream) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        $source = $this->bundleSource();
        $missing = array_values(array_filter(
            $upstream,
            static fn (string $method): bool => !str_contains($source, "'".$method."'"),
        ));

        // Both directions: a method upstream adds and nothing here wraps fails, and so
        // does one that gets wrapped without being struck off this list.
        self::assertSame(self::UNWRAPPED, $missing, 'The set of unwrapped native methods has changed: '.implode(', ', $missing));
    }

    public function testTheMethodCountHasNotChanged(): void
    {
        $upstream = $this->upstreamMethods();

        if ([] === $upstream) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        // A change is not necessarily a bug, but it must be a decision: read the new
        // methods and update MOBILE-ANALYSIS.md before bumping this.
        self::assertCount(self::EXPECTED_METHODS, $upstream);
    }

    /**
     * The shapes that were invisible, named so a narrowing of the parser fails here for
     * the stated reason instead of showing up as a count that is quietly five short.
     *
     * `PendingLocationWatch::start()` picks the method with a ternary and
     * `PendingGeolocation::get()` with a `match` — neither writes the name where the call
     * is made, which is the only place the old regex looked.
     */
    public function testAMethodNameComputedAboveTheCallSiteIsStillDiscovered(): void
    {
        $upstream = $this->upstreamMethods();

        if ([] === $upstream) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        foreach ([
            'Geolocation.StartBackgroundWatch',
            'Geolocation.WatchPosition',
            'Geolocation.CheckPermissions',
            'Geolocation.GetCurrentPosition',
            'Geolocation.RequestPermissions',
        ] as $method) {
            self::assertContains(
                $method,
                $upstream,
                sprintf('%s is chosen by a ternary or a match rather than written at the call site, and this test has gone blind to it again.', $method),
            );
        }
    }

    /**
     * Every native method upstream calls, from the parser BridgePayloadKeyContractTest
     * uses to read the same call sites.
     *
     * Shared rather than reimplemented: this used to match a quoted literal straight
     * after `nativephp_call(`, which is only one of the three shapes upstream writes.
     * A name assigned above the call — by a ternary in `PendingLocationWatch::start()`,
     * by a `match` in `PendingGeolocation::get()` — never matched, so five methods were
     * outside a test whose whole job is to notice a method being outside it.
     *
     * @return list<string>
     */
    private function upstreamMethods(): array
    {
        $names = array_keys($this->upstreamPayloads());
        sort($names);

        return $names;
    }

    private function bundleSource(): string
    {
        $source = '';

        foreach ($this->phpFiles(__DIR__.'/../src') as $file) {
            $source .= file_get_contents($file);
        }

        return $source;
    }
}
