<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

/**
 * One parser for every question asked about the mobile bridge, because two would drift.
 *
 * `BridgeCoverageTest` asks which methods upstream calls; `BridgePayloadKeyContractTest`
 * asks what each call carries. Both questions start by finding a `nativephp_call()` and
 * working out which native method it names — and the two used to answer that differently.
 * Coverage matched a quoted literal straight after the parenthesis, so upstream's two
 * shapes for a computed name were invisible to it: `PendingLocationWatch::start()` picks
 * between `Geolocation.StartBackgroundWatch` and `Geolocation.WatchPosition` with a
 * ternary, and `PendingGeolocation::get()` picks between three more with a `match`. Five
 * methods went undiscovered, the pinned surface read 57 instead of 62, and none of the
 * five was in the list of methods nothing here wraps — so a test named "the only
 * unwrapped bridge methods are the ones named here" reported OK about five it never saw.
 */
trait ParsesNativeCalls
{
    /** Upstream's own PHP wrappers, which exist for every method the bridge has. */
    private const UPSTREAM_WRAPPERS = 'upstream/np-mobile/src';

    /**
     * Paths that contain the text of a `nativephp_call()` but not a call to one.
     *
     * `jump_bridge_functions.php` declares the function, `JumpBridge` and
     * `Http/Controllers/NativeCallController` forward a method name that arrives at
     * runtime, `Testing/FakeBridge` is a double, and `Commands/PluginCreateCommand`
     * writes `'{$name}.Execute'` into a heredoc that scaffolds a plugin — a template,
     * not a method the bridge has.
     *
     * @var list<string>
     */
    private const NOT_CALL_SITES = ['Testing', 'Commands', 'Http', 'JumpBridge', 'jump_bridge_functions'];

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

        return $this->phpPayloads($root, '/nativephp_call\s*\(/', self::NOT_CALL_SITES);
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
