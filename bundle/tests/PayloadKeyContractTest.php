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
 * `->post()`, `->get()` and `->delete()`. Upstream's: `req.body`, `req.query` and
 * `req.params` reads inside each express handler, including destructuring. A handler that
 * forwards the whole body — `notifyLaravel('events', req.body)` — is exempt, since every
 * key reaches PHP by definition.
 *
 * Skips when the runtime sources are not checked out, exactly as ContractCoverageTest does.
 */
final class PayloadKeyContractTest extends TestCase
{
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

        self::assertGreaterThan(40, $checked, 'The parser found almost nothing to compare, which means it has stopped working.');
        self::assertSame([], $problems, "A payload key the runtime never reads is a silent no-op:\n".implode("\n", $problems));
    }

    /**
     * Literal payload arrays we pass to the transport, keyed by endpoint.
     *
     * Only top-level string keys of a literal array — a payload built up dynamically
     * (PendingWindow, PendingNotification) is not visible here, and is covered by the
     * fixtures those classes have instead.
     *
     * @return array<string, list<string>>
     */
    private function payloadsWeSend(): array
    {
        $found = [];

        foreach ($this->sourceFiles(__DIR__.'/../src') as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match_all('/->(?:post|get|delete)\(\s*\'([^\']+)\'\s*,\s*(?=\[)/', $source, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $i => [$endpoint, $_]) {
                $start = $matches[0][$i][1] + \strlen($matches[0][$i][0]);
                $literal = $this->balancedArray($source, $start);

                foreach ($this->topLevelKeys($literal) as $key) {
                    $found[rtrim($endpoint, '/')][$key] = true;
                }
            }
        }

        return array_map(static fn (array $keys): array => array_keys($keys), $found);
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
