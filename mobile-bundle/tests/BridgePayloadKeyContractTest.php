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
 * skipped rather than guessed at — which covered barely half the bridge, so the third
 * check reads upstream's own PHP wrappers instead. Those exist for every method, so
 * nothing is skipped and the number compared is asserted.
 *
 * Skips when the upstream mobile sources are not checked out, as BridgeCoverageTest does.
 */
final class BridgePayloadKeyContractTest extends TestCase
{
    private const ANDROID = 'upstream/np-mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge';
    private const IOS = 'upstream/np-mobile/resources/xcode/NativePHP/Bridge';
    private const UPSTREAM_WRAPPERS = 'upstream/np-mobile/src';

    /**
     * How many methods this bundle calls, all of which the wrapper comparison covers.
     * Named rather than floored so a discovery regression fails loudly instead of
     * passing with less. Upstream's bridge is wider — BridgeCoverageTest owns that
     * number and the three methods nothing here wraps.
     */
    private const METHODS_WE_CALL = 54;

    /**
     * Methods whose upstream payload merges a caller-supplied options array, so its key
     * set is open and an extra key of ours cannot be called wrong. Both are Camera's:
     * `Camera::getPhoto(array $options)` and `Camera::recordVideo(array $options)` pass
     * the bag through untouched, and no Camera handler is in this checkout to adjudicate
     * what it accepts — the handlers live in a plugin.
     */
    private const OPEN_PAYLOAD = ['Camera.GetPhoto', 'Camera.RecordVideo'];

    /**
     * Divergences that are real and not yet fixed, named here so they would be visible
     * in code rather than invisible in a skip. Each is asserted to still diverge, so an
     * entry cannot outlive the problem it describes — which is why this is now empty:
     * both Geolocation buffer methods that were listed here have been fixed.
     *
     * @var array<string, string>
     */
    private const KNOWN_DIVERGENT = [];

    /**
     * Methods where upstream's payload carries a key this bundle deliberately omits.
     *
     * All five omit the same pair — `id` and `event`, the correlation handle and
     * listener class that route an asynchronous result back to PHP. This bundle
     * implements no PHP-side receiver for mobile events (see docs/mobile-api.md), so
     * there is nothing for a correlation id to correlate; the result reaches the page
     * through the host's JS bridge instead.
     *
     * Not to be confused with Geolocation's watch `id`, which is a control handle
     * rather than an event correlation: `clearWatch`, `drainWatch` and `trimWatch`
     * each address one of several concurrent streams with it, so omitting that one was
     * a defect and is fixed. Each entry here is asserted to still be incomplete.
     *
     * @var list<string>
     */
    private const INCOMPLETE_PAYLOAD = [
        'Biometric.Prompt',
        'Camera.GetPhoto',
        'Camera.PickMedia',
        'Camera.RecordVideo',
        'Microphone.Start',
    ];

    public function testEveryKeyWeSendIsReadByTheAndroidHost(): void
    {
        $this->assertKeysAreRead($this->androidHandlers(), 'Android');
    }

    public function testEveryKeyWeSendIsReadByTheIosHost(): void
    {
        $this->assertKeysAreRead($this->iosHandlers(), 'iOS');
    }

    /**
     * Every key we send must be one upstream's own PHP wrapper sends.
     *
     * The two checks above read the native handlers, and can only see the 27 methods
     * `BridgeFunctionRegistration.{kt,swift}` names — the other half of the bridge ships
     * in plugins this checkout does not contain, so `continue` dropped them without
     * saying so. Four wrong keys reached main through that hole. Upstream's own
     * `src/*.php` wrappers are in the checkout for every method and carry the same wire
     * contract, so they close it: nothing is skipped, the number of methods compared is
     * asserted rather than hoped for, and a method with no wrapper at all is a failure
     * instead of a silent pass.
     */
    public function testEveryKeyWeSendIsOneUpstreamsOwnWrapperSends(): void
    {
        $upstream = $this->upstreamPayloads();

        if ([] === $upstream) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        // Upstream wraps everything we do and more, so a floor rather than a count:
        // upstream's own growth cannot turn this red, but a broken parser can.
        self::assertGreaterThanOrEqual(self::METHODS_WE_CALL, \count($upstream), 'The upstream wrapper parser has stopped finding payloads.');

        $compared = 0;
        $withoutWrapper = [];
        $problems = [];
        $stale = [];

        foreach ($this->payloadsWeSend() as $method => $keys) {
            if (!isset($upstream[$method])) {
                $withoutWrapper[] = $method;

                continue;
            }

            ++$compared;
            $unknown = array_values(array_diff($keys, $upstream[$method]));

            if (isset(self::KNOWN_DIVERGENT[$method])) {
                if ([] === $unknown) {
                    $stale[] = $method;
                }

                continue;
            }

            if ([] !== $unknown && !\in_array($method, self::OPEN_PAYLOAD, true)) {
                $problems[] = sprintf(
                    '%s sends [%s] — upstream sends [%s]',
                    $method,
                    implode(', ', $unknown),
                    [] === $upstream[$method] ? 'nothing' : implode(', ', $upstream[$method]),
                );
            }
        }

        self::assertSame([], $withoutWrapper, "No upstream wrapper to compare against, so these went unchecked:\n".implode("\n", $withoutWrapper));
        self::assertSame(self::METHODS_WE_CALL, $compared, 'Fewer methods were compared than this bundle calls — the discovery has silently narrowed.');
        self::assertSame([], $stale, "These no longer diverge; drop them from KNOWN_DIVERGENT:\n".implode("\n", $stale));
        self::assertSame([], $problems, "A key upstream never sends is a call that succeeds and does nothing:\n".implode("\n", $problems));
    }

    /**
     * And the other direction: every key upstream's wrapper sends, we must send too.
     *
     * The check above only catches a key we invent. An omitted key is invisible to it,
     * because an empty payload differs from nothing upstream sends — which is how
     * `Geolocation.ClearWatch` and `Geolocation.StopBackgroundWatch` came to send no
     * payload at all while upstream addresses a specific watch by `id`. A watch that
     * cannot be named cannot be stopped, and a location stream that will not stop is a
     * battery drain the user cannot escape.
     *
     * The five methods that legitimately send less are named in INCOMPLETE_PAYLOAD with
     * the reason, and asserted to still send less so the list cannot outlive the gap.
     */
    public function testEveryKeyUpstreamsOwnWrapperSendsIsOneWeSendToo(): void
    {
        $upstream = $this->upstreamPayloads();

        if ([] === $upstream) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        $compared = 0;
        $problems = [];
        $stale = [];

        foreach ($this->payloadsWeSend() as $method => $keys) {
            if (!isset($upstream[$method])) {
                continue;
            }

            ++$compared;
            $missing = array_values(array_diff($upstream[$method], $keys));

            if (\in_array($method, self::INCOMPLETE_PAYLOAD, true)) {
                if ([] === $missing) {
                    $stale[] = $method;
                }

                continue;
            }

            if ([] !== $missing) {
                $problems[] = sprintf(
                    '%s omits [%s] — upstream sends [%s]',
                    $method,
                    implode(', ', $missing),
                    implode(', ', $upstream[$method]),
                );
            }
        }

        self::assertSame(self::METHODS_WE_CALL, $compared, 'Fewer methods were compared than this bundle calls — the discovery has silently narrowed.');
        self::assertSame([], $stale, "These no longer send less than upstream; drop them from INCOMPLETE_PAYLOAD:\n".implode("\n", $stale));
        self::assertSame([], $problems, "A key upstream sends and we do not is an instruction the host never receives:\n".implode("\n", $problems));
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
        // BridgeFunctionRegistration and the rest ship in plugins this checkout does not
        // contain. The floor exists to catch the parser breaking, not to claim coverage —
        // the wrapper check below is the one that compares every method.
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
     * Payloads we pass to `call()` and `dispatch()`, keyed by native method.
     *
     * @return array<string, list<string>>
     */
    private function payloadsWeSend(): array
    {
        return $this->phpPayloads(__DIR__.'/../src/Api', '/\$this->bridge->(?:call|dispatch)\s*\(/');
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

    /**
     * Payloads upstream's own wrappers pass to `nativephp_call()`, keyed by native method.
     *
     * @return array<string, list<string>>
     */
    private function upstreamPayloads(): array
    {
        $root = \dirname(__DIR__, 2).'/'.self::UPSTREAM_WRAPPERS;

        if (!is_dir($root)) {
            return [];
        }

        return $this->phpPayloads($root, '/nativephp_call\s*\(/', ['Testing', 'Commands', 'Http', 'JumpBridge', 'jump_bridge_functions']);
    }

    /**
     * Native method → the payload keys the PHP under $root sends with it.
     *
     * One parser for both sides, because two would drift. A payload built in a variable,
     * a ternary or a `match` counts: the regex the Android and iOS checks used to rely on
     * only saw a literal array typed straight after the method name, which is why
     * `Device.ToggleFlashlight`'s `on` and `Perf.StartCaptureWindow`'s `label` were
     * invisible to a test that reads the handlers ignoring both.
     *
     * @param list<string> $skip path fragments to leave out
     *
     * @return array<string, list<string>>
     */
    private function phpPayloads(string $root, string $callPattern, array $skip = []): array
    {
        $found = [];

        foreach ($this->phpFiles($root) as $file) {
            foreach ($skip as $fragment) {
                if (str_contains($file, $fragment)) {
                    continue 2;
                }
            }

            $source = (string) file_get_contents($file);

            if (!preg_match_all($callPattern, $source, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $bodies = $this->functionBodies($source);

            foreach ($matches[0] as [$literal, $at]) {
                $body = $this->innermostBody($bodies, $at);
                $arguments = $this->splitArguments($this->balanced($source, $at + \strlen($literal) - 1, '(', ')'));

                if ([] === $arguments) {
                    continue;
                }

                foreach ($this->methodNames($arguments[0], $body) as $method) {
                    $found[$method] ??= [];

                    foreach ($this->payloadKeys($arguments[1] ?? "'{}'", $body) as $key) {
                        $found[$method][$key] = true;
                    }
                }
            }
        }

        return array_map(static fn (array $keys): array => array_keys($keys), $found);
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The method name a call's first argument resolves to: a literal, or every dotted
     * string assigned to the variable it names (upstream picks one with `match` or `?:`).
     *
     * @return list<string>
     */
    private function methodNames(string $argument, string $body): array
    {
        if (preg_match("/^'([^']+)'$/", $argument, $matches)) {
            return [$matches[1]];
        }

        if (!preg_match('/^\$(\w+)$/', $argument, $matches)) {
            return [];
        }

        $names = [];

        foreach ($this->assignments($body, $matches[1]) as $statement) {
            preg_match_all("/'([A-Za-z][A-Za-z0-9_]*\.[A-Za-z][A-Za-z0-9_.]*)'/", $statement, $found);

            foreach ($found[1] as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * The keys a call's payload argument carries, whether written inline or built above.
     *
     * @return list<string>
     */
    private function payloadKeys(string $argument, string $body): array
    {
        $keys = [];

        foreach ($this->arrayLiterals($argument) as $literal) {
            foreach ($this->topLevelKeys($literal) as $key) {
                $keys[$key] = true;
            }
        }

        if ([] !== $keys || !preg_match('/\$(\w+)/', $argument, $matches)) {
            return array_keys($keys);
        }

        foreach ($this->assignments($body, $matches[1]) as $statement) {
            foreach ($this->arrayLiterals($statement) as $literal) {
                foreach ($this->topLevelKeys($literal) as $key) {
                    $keys[$key] = true;
                }
            }
        }

        foreach ($this->indexAssignments($body, $matches[1]) as $key) {
            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    /**
     * The right-hand side of every `$name = ...;` in $body.
     *
     * @return list<string>
     */
    private function assignments(string $body, string $name): array
    {
        $statements = [];
        $needle = '$'.$name;
        $offset = 0;

        while (false !== ($at = strpos($body, $needle, $offset))) {
            $offset = $at + \strlen($needle);
            $rest = substr($body, $offset);

            if (preg_match('/^\s*=(?!=)/', $rest, $matches)) {
                $statements[] = $this->statement(substr($rest, \strlen($matches[0])));
            }
        }

        return $statements;
    }

    /**
     * Keys added one at a time, as `$payload['quality'] = ...`.
     *
     * @return list<string>
     */
    private function indexAssignments(string $body, string $name): array
    {
        preg_match_all('/\$'.preg_quote($name, '/')."\[\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*\]\s*=(?!=)/", $body, $matches);

        return $matches[1];
    }

    /** Everything up to the `;` that ends the statement, ignoring nesting and strings. */
    private function statement(string $source): string
    {
        $depth = 0;
        $quote = null;

        for ($i = 0, $length = \strlen($source); $i < $length; ++$i) {
            $char = $source[$i];

            if (null !== $quote) {
                if ('\\' === $char) {
                    ++$i;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ("'" === $char || '"' === $char) {
                $quote = $char;
            } elseif (false !== strpos('([{', $char)) {
                ++$depth;
            } elseif (false !== strpos(')]}', $char)) {
                --$depth;
            } elseif (';' === $char && 0 === $depth) {
                return substr($source, 0, $i);
            }
        }

        return $source;
    }

    /**
     * Every outermost `[...]` in a chunk of PHP — a `match` has one arm per branch.
     *
     * @return list<string>
     */
    private function arrayLiterals(string $source): array
    {
        $literals = [];
        $depth = 0;
        $quote = null;
        $start = null;

        for ($i = 0, $length = \strlen($source); $i < $length; ++$i) {
            $char = $source[$i];

            if (null !== $quote) {
                if ('\\' === $char) {
                    ++$i;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ("'" === $char || '"' === $char) {
                $quote = $char;
            } elseif ('[' === $char) {
                if (0 === $depth) {
                    $start = $i;
                }

                ++$depth;
            } elseif (']' === $char) {
                if (1 === $depth && null !== $start) {
                    $literals[] = substr($source, $start, $i - $start + 1);
                    $start = null;
                }

                $depth = max(0, $depth - 1);
            }
        }

        return $literals;
    }

    /**
     * The body of every function in a PHP source, with the offset it starts at.
     *
     * @return list<array{int, string}>
     */
    private function functionBodies(string $source): array
    {
        preg_match_all('/function\s+\w*\s*\(/', $source, $matches, \PREG_OFFSET_CAPTURE);

        $bodies = [];

        foreach ($matches[0] as [$_, $at]) {
            $open = strpos($source, '{', $at);

            if (false === $open) {
                continue;
            }

            $body = $this->balanced($source, $open, '{', '}');

            if ('' !== $body) {
                $bodies[] = [$open, $body];
            }
        }

        return $bodies;
    }

    /** @param list<array{int, string}> $bodies */
    private function innermostBody(array $bodies, int $at): string
    {
        $found = '';
        $shortest = \PHP_INT_MAX;

        foreach ($bodies as [$offset, $body]) {
            $length = \strlen($body);

            if ($offset <= $at && $at < $offset + $length && $length < $shortest) {
                $found = $body;
                $shortest = $length;
            }
        }

        return $found;
    }

    /**
     * A parenthesised argument list split on its top-level commas.
     *
     * @return list<string>
     */
    private function splitArguments(string $parenthesised): array
    {
        $arguments = [];
        $source = substr($parenthesised, 1, -1);
        $current = '';
        $depth = 0;
        $quote = null;

        for ($i = 0, $length = \strlen($source); $i < $length; ++$i) {
            $char = $source[$i];
            $current .= $char;

            if (null !== $quote) {
                if ('\\' === $char) {
                    $current .= $source[++$i] ?? '';
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ("'" === $char || '"' === $char) {
                $quote = $char;
            } elseif (false !== strpos('([{', $char)) {
                ++$depth;
            } elseif (false !== strpos(')]}', $char)) {
                --$depth;
            } elseif (',' === $char && 0 === $depth) {
                $arguments[] = trim(substr($current, 0, -1));
                $current = '';
            }
        }

        if ('' !== trim($current)) {
            $arguments[] = trim($current);
        }

        return $arguments;
    }

    /** The $open-balanced run starting at $start, brackets or parentheses. */
    private function balanced(string $source, int $start, string $open, string $close): string
    {
        $depth = 0;

        for ($i = $start, $length = \strlen($source); $i < $length; ++$i) {
            if ($open === $source[$i]) {
                ++$depth;
            } elseif ($close === $source[$i]) {
                if (0 === --$depth) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return substr($source, $start);
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
