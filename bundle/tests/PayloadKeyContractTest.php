<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every key this bundle sends must be one the runtime actually reads.
 *
 * `ContractCoverageTest` checks that each endpoint is called; this checks what is sent to
 * it. The difference is the whole of a class of bug that cost this project nine defects on
 * the mobile side, where `<image>` sent `source` and both renderers read `src`: the call
 * succeeds, the runtime answers 200, and the feature silently does nothing. Nothing about a
 * green suite or a running app catches it, because a key nobody reads looks exactly like a
 * key that works.
 *
 * Both halves are parsed rather than listed. Ours: literal payload arrays passed to
 * `->post()`, `->get()` and `->delete()`, plus the payloads the fluent builders assemble
 * key by key — `PendingWindow` alone carries 38 of them, which used to be the largest
 * payload in the bundle that nothing compared against upstream. Upstream's: `req.body`, `req.query` and
 * `req.params` reads inside each express handler, including destructuring. A handler that
 * forwards the whole body — `notifyLaravel('events', req.body)` — is exempt, since every
 * key reaches PHP by definition.
 *
 * Skips when the runtime sources are not checked out, exactly as ContractCoverageTest does.
 */
final class PayloadKeyContractTest extends TestCase
{
    /**
     * Endpoints actually compared. ContractCoverageTest pins the mount table this relies
     * on, so a module upstream adds cannot quietly drop out of both.
     */
    private const int EXPECTED_COMPARISONS = 55;

    /** Mount points from the runtime's api.ts, as ContractCoverageTest lists them. */
    private const MOUNTS = [
        'alert' => 'alert', 'app' => 'app', 'autoUpdater' => 'auto-updater',
        'broadcasting' => 'broadcast', 'childProcess' => 'child-process',
        'clipboard' => 'clipboard', 'contextMenu' => 'context', 'debug' => 'debug',
        'dialog' => 'dialog', 'dock' => 'dock', 'globalShortcut' => 'global-shortcuts',
        'menu' => 'menu', 'menuBar' => 'menu-bar', 'notification' => 'notification',
        'powerMonitor' => 'power-monitor', 'process' => 'process',
        'progressBar' => 'progress-bar', 'screen' => 'screen', 'settings' => 'settings',
        'shell' => 'shell', 'system' => 'system', 'window' => 'window',
    ];

    public function testEveryKeyWeSendIsOneTheRuntimeReads(): void
    {
        $upstream = $this->upstreamHandlers();

        if ([] === $upstream) {
            self::markTestSkipped('Runtime sources not available.');
        }

        $checked = 0;
        $problems = [];

        foreach ($this->payloadsWeSend() as $endpoint => $keys) {
            $reads = $upstream[$endpoint] ?? null;

            // Unknown endpoint: ContractCoverageTest owns that question. Whole-body
            // forwarding: every key reaches PHP, so there is nothing to check.
            if (null === $reads || \in_array('*', $reads, true)) {
                continue;
            }

            ++$checked;
            $unknown = array_values(array_diff($keys, $reads));

            if ([] !== $unknown) {
                $problems[] = sprintf(
                    '%s sends [%s] — the handler reads [%s]',
                    $endpoint,
                    implode(', ', $unknown),
                    implode(', ', $reads),
                );
            }
        }

        // Exact, not a floor: a floor lets the parser stop recognising a call shape and
        // compare fewer endpoints while still reporting OK, which is how the clipboard's
        // query-string calls sat outside this check unnoticed. Changing it is a decision.
        self::assertSame(self::EXPECTED_COMPARISONS, $checked, 'A different number of endpoints was compared than this test accounts for.');

        // Stated, not implied: the builders are the payloads most worth comparing and the
        // ones most easily left out, so name them rather than trusting the total.
        foreach (['window/open', 'notification', 'menu-bar/create', 'dialog/open'] as $endpoint) {
            self::assertArrayHasKey(
                $endpoint,
                $this->payloadsWeSend(),
                sprintf('%s is built by a fluent builder and has to be part of this comparison.', $endpoint),
            );
        }
        self::assertSame([], $problems, "A payload key the runtime never reads is a silent no-op:\n".implode("\n", $problems));
    }

    /**
     * The payload keys we send, keyed by endpoint.
     *
     * Two shapes: the top-level keys of a literal array handed to the transport, and the
     * keys a builder assembles into `$this->payload` before posting them. The second was
     * previously waved through with a comment saying those classes had fixtures of their
     * own — true, but a fixture asserts what we send against ourselves, which is exactly
     * the comparison that cannot catch a key nobody reads.
     *
     * @return array<string, list<string>>
     */
    private function payloadsWeSend(): array
    {
        $found = [];

        foreach ($this->sourceFiles(__DIR__.'/../src') as $file) {
            $source = (string) file_get_contents($file);

            // The optional `.$value` tail matters: ClipboardManager posts to
            // `'clipboard/text?type='.$type->value`, and requiring the comma straight after
            // the quote meant its three payload-carrying calls were not in this comparison
            // at all — renaming the key the runtime reads for any of them changed nothing.
            if (!preg_match_all('/->(?:post|get|delete)\(\s*\'([^\']+)\'(\s*\.[^,]+)?\s*,\s*(?=\[)/', $source, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $i => [$endpoint, $_]) {
                // A tail is only readable when the literal already carries the query
                // string. `'settings/'.rawurlencode($key)` has its *path* completed at
                // runtime, so pairing it with a handler would compare the wrong route —
                // it stays out, exactly as before.
                if ('' !== $matches[2][$i][0] && !str_contains($endpoint, '?')) {
                    continue;
                }

                $start = $matches[0][$i][1] + \strlen($matches[0][$i][0]);
                $literal = $this->balancedArray($source, $start);

                foreach ($this->topLevelKeys($literal) as $key) {
                    $found[$this->normalise($endpoint)][$key] = true;
                }
            }
        }

        foreach ($this->builderPayloads() as $endpoint => $keys) {
            foreach ($keys as $key) {
                $found[$endpoint][$key] = true;
            }
        }

        return array_map(static fn (array $keys): array => array_keys($keys), $found);
    }

    /**
     * A fluent builder's payload, keyed by the endpoint it posts to.
     *
     * A builder writes into one array and sends it in one place, so the file itself is
     * the association. Inheritance has to be followed, though: `PendingDialog` holds
     * every key and posts nothing, while `PendingOpenDialog` and `PendingSaveDialog`
     * each post to a different endpoint — so the parent's keys count towards both.
     *
     * @return array<string, list<string>>
     */
    private function builderPayloads(): array
    {
        $classes = [];

        foreach ($this->sourceFiles(__DIR__.'/../src') as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match('/(?:final |abstract )?class (\w+)(?: extends (\w+))?/', $source, $class)) {
                continue;
            }

            preg_match_all('/(?:\$this->payload|\$payload)\[\'([^\']+)\'\]\s*\??=[^=]/', $source, $keys);
            preg_match_all('/->post\(\s*\'([^\']+)\'\s*,\s*\$(?:this->)?payload/', $source, $posts);

            $endpoints = array_values(array_unique($posts[1]));

            $classes[$class[1]] = [
                'parent' => $class[2] ?? null,
                'keys' => array_values(array_unique($keys[1])),
                // More than one endpoint from one payload would make the association
                // ambiguous, and guessing is how a check like this compares the wrong pair.
                'endpoint' => 1 === \count($endpoints) ? $this->normalise($endpoints[0]) : null,
            ];
        }

        $found = [];

        foreach ($classes as $class) {
            if (null === $class['endpoint']) {
                continue;
            }

            $keys = $class['keys'];

            for ($parent = $class['parent']; null !== $parent && isset($classes[$parent]); $parent = $classes[$parent]['parent']) {
                $keys = [...$keys, ...$classes[$parent]['keys']];
            }

            $found[$class['endpoint']] = array_values(array_unique([...($found[$class['endpoint']] ?? []), ...$keys]));
        }

        return $found;
    }

    /** The keys each express handler reads, keyed by endpoint; `*` means it forwards the lot. */
    private function upstreamHandlers(): array
    {
        $dir = __DIR__.'/../../upstream/np-desktop/resources/electron/electron-plugin/src/server/api';

        if (!is_dir($dir)) {
            return [];
        }

        $handlers = [];

        foreach (glob($dir.'/*.ts') ?: [] as $file) {
            $mount = self::MOUNTS[basename($file, '.ts')] ?? null;

            if (null === $mount) {
                continue;
            }

            $source = (string) file_get_contents($file);
            preg_match_all(
                '/router\.(?:get|post|delete|put)\(\s*\'([^\']*)\'([\s\S]*?)(?=router\.(?:get|post|delete|put)\(|$)/',
                $source,
                $routes,
                \PREG_SET_ORDER,
            );

            foreach ($routes as [$_, $path, $body]) {
                $endpoint = rtrim($mount.$path, '/');
                $keys = [];

                preg_match_all('/req\.(?:body|query|params)\.([A-Za-z_][A-Za-z0-9_]*)/', $body, $direct);
                $keys = [...$keys, ...$direct[1]];

                preg_match_all('/\{([^}]*)\}\s*=\s*req\.(?:body|query|params)/', $body, $destructured);

                foreach ($destructured[1] as $group) {
                    preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $group, $names);
                    // The declaration keyword is inside the capture; leaving it in puts
                    // "const" in the failure message's list of keys the handler reads.
                    $keys = [...$keys, ...array_diff($names[0], ['const', 'let', 'var'])];
                }

                // Whole-body forwarding only — `notifyLaravel('events', req.body)` or a
                // spread. `const { key } = req.body` is destructuring, and treating that as
                // "reads everything" would exempt most of the surface from this check,
                // which is how the first version of this test quietly compared ten
                // endpoints instead of fifty.
                if (preg_match('/\.\.\.req\.body|\(\s*req\.body\s*[,)]|(?<![}\s])\s*=\s*req\.body\b/', $body)) {
                    $keys[] = '*';
                }

                $handlers[$endpoint] = array_values(array_unique([...($handlers[$endpoint] ?? []), ...$keys]));
            }
        }

        return $handlers;
    }

    /**
     * An endpoint as the runtime's router sees it: no query string, no trailing slash.
     *
     * `clipboard/text?type=` and `clipboard/text` are one route; keeping them apart meant
     * the first had no upstream handler to compare against and was silently skipped.
     */
    private function normalise(string $endpoint): string
    {
        return rtrim(explode('?', $endpoint)[0], '/');
    }

    /** @return list<string> */
    private function sourceFiles(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** The array literal starting at `$start`, balanced across nested arrays. */
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

    /**
     * String keys at the top level of an array literal — a nested array's keys belong to
     * the value, not to the payload.
     *
     * @return list<string>
     */
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
