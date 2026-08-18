<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every key this bundle sends to a native method must be one the hosts actually read.
 *
 * `BridgeCoverageTest` checks that every upstream method has a wrapper; this checks what the
 * wrapper puts in it. The gap between those two questions is where this package has lost
 * more defects than anywhere else — nine on the element wire, and four more found by writing
 * this test: `Device.Vibrate` sent a `duration` neither host has, and all three
 * `Perf.Simulate*` methods sent a `target` string where both require `callback_id` as a
 * number, so every simulated interaction returned true in PHP and did nothing on a device.
 *
 * Both hosts are parsed, because agreeing with one is not agreeing with the contract:
 * `BridgeFunctionRegistration.{kt,swift}` maps a method name to a function class, and that
 * class's body is read for `parameters["key"]`. A method whose class cannot be found is
 * skipped rather than guessed at.
 *
 * Skips when the upstream mobile sources are not checked out, as BridgeCoverageTest does.
 */
final class BridgePayloadKeyContractTest extends TestCase
{
    private const ANDROID = 'upstream/np-mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge';
    private const IOS = 'upstream/np-mobile/resources/xcode/NativePHP/Bridge';

    public function testEveryKeyWeSendIsReadByTheAndroidHost(): void
    {
        $this->assertKeysAreRead($this->androidHandlers(), 'Android');
    }

    public function testEveryKeyWeSendIsReadByTheIosHost(): void
    {
        $this->assertKeysAreRead($this->iosHandlers(), 'iOS');
    }

    /** @param array<string, list<string>> $handlers */
    private function assertKeysAreRead(array $handlers, string $platform): void
    {
        if ([] === $handlers) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        $checked = 0;
        $problems = [];

        foreach ($this->payloadsWeSend() as $method => $keys) {
            if (!isset($handlers[$method])) {
                continue;
            }

            ++$checked;
            $unknown = array_values(array_diff($keys, $handlers[$method]));

            if ([] !== $unknown) {
                $problems[] = sprintf(
                    '%s sends [%s] — the %s handler reads [%s]',
                    $method,
                    implode(', ', $unknown),
                    $platform,
                    [] === $handlers[$method] ? 'nothing' : implode(', ', $handlers[$method]),
                );
            }
        }

        // Eight, measured rather than hoped for: only 27 of the 54 methods are in
        // BridgeFunctionRegistration, the rest are dispatched by a path this checkout does
        // not expose in parseable form, and only some of ours carry a literal payload. The
        // floor exists to catch the parser breaking, not to claim full coverage.
        self::assertGreaterThanOrEqual(8, $checked, sprintf('Almost no %s handlers were matched, so this test has stopped working.', $platform));
        self::assertSame([], $problems, "A key the host never reads is a call that succeeds and does nothing:\n".implode("\n", $problems));
    }

    /**
     * A key the handler bails without must be one we send.
     *
     * The mirror of the check above, and the one that finds the worse bug: a handler
     * reading `parameters["callback_id"] ?: return mapOf("success" to false)` does nothing
     * at all without it. All three Perf.Simulate* methods were in exactly that state —
     * sending `target`, which no host reads, and omitting the id every host requires.
     */
    public function testEveryKeyAHandlerRequiresIsOneWeSend(): void
    {
        $required = $this->requiredKeys(self::ANDROID.'/BridgeFunctionRegistration.kt', self::ANDROID.'/functions', 'kt', '/register\("([^"]+)",\s*([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)/');

        if ([] === $required) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        $ours = $this->payloadsWeSend();
        $problems = [];

        foreach ($required as $method => $keys) {
            if (!isset($ours[$method])) {
                continue;
            }

            $missing = array_values(array_diff($keys, $ours[$method]));

            if ([] !== $missing) {
                $problems[] = sprintf('%s omits [%s], which the handler returns success: false without', $method, implode(', ', $missing));
            }
        }

        self::assertSame([], $problems, "The host bails without these:\n".implode("\n", $problems));
    }

    /**
     * Keys whose absence makes the handler return early.
     *
     * @return array<string, list<string>>
     */
    private function requiredKeys(string $registration, string $dir, string $extension, string $pattern): array
    {
        $required = [];

        foreach ($this->handlerBodies($registration, $dir, $extension, $pattern) as $method => $body) {
            preg_match_all('/parameters\["([^"]+)"\][^\n]*\?:\s*return/', $body, $matches);

            if ([] !== $matches[1]) {
                $required[$method] = array_values(array_unique($matches[1]));
            }
        }

        return $required;
    }

    /**
     * Literal payloads we pass to `call()` and `dispatch()`, keyed by native method.
     *
     * @return array<string, list<string>>
     */
    private function payloadsWeSend(): array
    {
        $found = [];

        foreach (glob(__DIR__.'/../src/Api/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match_all('/->(?:call|dispatch)\(\s*\'([^\']+)\'\s*,\s*(?=\[)/', $source, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $i => [$method, $_]) {
                $start = $matches[0][$i][1] + \strlen($matches[0][$i][0]);

                foreach ($this->topLevelKeys($this->balancedArray($source, $start)) as $key) {
                    $found[$method][$key] = true;
                }
            }
        }

        return array_map(static fn (array $keys): array => array_keys($keys), $found);
    }

    /** @return array<string, list<string>> */
    private function androidHandlers(): array
    {
        return $this->handlers(
            self::ANDROID.'/BridgeFunctionRegistration.kt',
            self::ANDROID.'/functions',
            'kt',
            '/register\("([^"]+)",\s*([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)/',
        );
    }

    /** @return array<string, list<string>> */
    private function iosHandlers(): array
    {
        return $this->handlers(
            self::IOS.'/BridgeFunctionRegistration.swift',
            self::IOS.'/Functions',
            'swift',
            '/register\("([^"]+)",\s*function:\s*([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)/',
        );
    }

    /**
     * Method name → the parameter keys its handler class reads.
     *
     * @return array<string, list<string>>
     */
    private function handlers(string $registration, string $dir, string $extension, string $pattern): array
    {
        $handlers = [];

        foreach ($this->handlerBodies($registration, $dir, $extension, $pattern) as $method => $body) {
            preg_match_all('/parameters\["([^"]+)"\]/', $body, $reads);
            $handlers[$method] = array_values(array_unique($reads[1]));
        }

        return $handlers;
    }

    /**
     * Method name → the source of the class that handles it.
     *
     * @return array<string, string>
     */
    private function handlerBodies(string $registration, string $dir, string $extension, string $pattern): array
    {
        $root = \dirname(__DIR__, 2).'/';

        if (!is_file($root.$registration)) {
            return [];
        }

        preg_match_all($pattern, (string) file_get_contents($root.$registration), $registrations, \PREG_SET_ORDER);

        $bodies = [];

        foreach ($registrations as [$_, $method, $group, $class]) {
            $file = sprintf('%s%s/%s.%s', $root, $dir, $group, $extension);

            if (!is_file($file)) {
                continue;
            }

            $body = $this->classBody((string) file_get_contents($file), $class);

            if ('' !== $body) {
                $bodies[$method] = $body;
            }
        }

        return $bodies;
    }

    /** The brace-balanced body of a named class, in Kotlin or Swift. */
    private function classBody(string $source, string $class): string
    {
        if (!preg_match('/class\s+'.preg_quote($class, '/').'\b/', $source, $_, \PREG_OFFSET_CAPTURE, 0)) {
            return '';
        }

        preg_match('/class\s+'.preg_quote($class, '/').'\b/', $source, $match, \PREG_OFFSET_CAPTURE);
        $open = strpos($source, '{', $match[0][1]);

        if (false === $open) {
            return '';
        }

        $depth = 0;

        for ($i = $open, $length = \strlen($source); $i < $length; ++$i) {
            if ('{' === $source[$i]) {
                ++$depth;
            } elseif ('}' === $source[$i]) {
                if (0 === --$depth) {
                    return substr($source, $open, $i - $open);
                }
            }
        }

        return substr($source, $open);
    }

    private function balancedArray(string $source, int $start): string
    {
        $depth = 0;

        for ($i = $start, $length = \strlen($source); $i < $length; ++$i) {
            if ('[' === $source[$i]) {
                ++$depth;
            } elseif (']' === $source[$i]) {
                if (0 === --$depth) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    /** @return list<string> */
    private function topLevelKeys(string $literal): array
    {
        $flat = '';
        $depth = 0;

        for ($i = 0, $length = \strlen($literal); $i < $length; ++$i) {
            $char = $literal[$i];

            if ('[' === $char) {
                ++$depth;
            }

            if ($depth <= 1) {
                $flat .= $char;
            }

            if (']' === $char) {
                --$depth;
            }
        }

        preg_match_all('/\'([A-Za-z_][A-Za-z0-9_]*)\'\s*=>/', $flat, $keys);

        return $keys[1];
    }
}
