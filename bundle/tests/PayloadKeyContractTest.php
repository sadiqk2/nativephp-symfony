<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every key this bundle sends must be one the runtime actually reads, and every key the
 * runtime reads without a guard of its own must be one this bundle always sends.
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
 * The second direction is newer and needs the runtime's *use* sites, not only its reads:
 * the question is not whether a handler mentions a key but whether it can manage without
 * one. `trimOptions(options)`, `icon || state.icon`, `windowPosition ?? 'trayCenter'` all
 * can; `tray.setTitle(label)` cannot. See methodCallArguments() for where that line is
 * drawn and why.
 *
 * Skips when the runtime sources are not checked out, exactly as ContractCoverageTest does.
 */
final class PayloadKeyContractTest extends TestCase
{
    /**
     * Endpoints actually compared. ContractCoverageTest pins the mount table this relies
     * on, so a module upstream adds cannot quietly drop out of both.
     */
    private const int EXPECTED_COMPARISONS = 57;

    /**
     * Endpoints compared in the omitted-key direction, and how many of them the runtime
     * actually demands something from.
     *
     * The second number is the one that matters. Every assertion below would pass with a
     * parser that found no requirements at all, so a count of the endpoints carrying one
     * is the only thing that keeps this direction from quietly becoming a no-op — the
     * same reason EXPECTED_COMPARISONS above is exact rather than a floor.
     *
     * The second number has moved twice, in opposite directions, and both moves are the
     * point of pinning it. `window/set-zoom-factor` stopped demanding one when upstream
     * merged this repository's own patch #137 and put `parseZoomFactor()` in front of the
     * read — a demand disappearing is exactly what a floor would have missed. Then
     * `window/fullscreen` and `system/print-file` arrived, each handing its key straight
     * to a call, and put it back over.
     */
    private const int EXPECTED_REVERSE_COMPARISONS = 58;
    private const int ENDPOINTS_THAT_DEMAND_A_KEY = 26;
    // One more than EXPECTED_COMPARISONS, and the extra is `alert/message`: it is built
    // with an `array_filter` rather than key by key, so the forward direction finds no
    // keys for it and never makes an entry, while this direction records that the endpoint
    // is sent to at all. The handler puts every one of its keys through `?? undefined`, so
    // there is nothing there to demand.

    /**
     * Method calls that hold a default of their own, so a bare argument to one is not a
     * demand for a key.
     *
     * `state` is the runtime's own module rather than an Electron object, and
     * `findWindow` answers `this.windows[id] || null` — an absent windowReference means
     * "no parent window", which is the app-modal dialog both dialog endpoints document.
     * The guard is asserted to still be there, so this entry cannot outlive its reason.
     *
     * @var array<string, array{string, string}>
     */
    private const CALLS_THAT_DEFAULT = [
        'state.findWindow' => ['server/state.ts', 'return this.windows[id] || null;'],
    ];

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
     * And the other direction: a key the runtime reads with no guard of its own has to be
     * in every payload, not only when the caller happened to set it.
     *
     * The check above only sees a key we invent. An omitted key is invisible to it, since
     * a key nobody sends looks exactly like a key nobody needs — and this bundle has now
     * lost three defects through that hole, all with the same shape. `window/open` omitted
     * `zoomFactor`, which the runtime hands to `setZoomFactor(parseFloat(zoomFactor))`, so
     * the page opened at NaN zoom; then it omitted `windowButtonVisibility`, handed
     * straight to `setWindowButtonVisibility()` on darwin, and a plain window lost the
     * macOS traffic lights; then `menu-bar/create` omitted `label` and `tooltip`, handed to
     * `Tray.setTitle()` and `Tray.setToolTip()`, which throw on `undefined` — after
     * `res.sendStatus(200)` has already told PHP the call worked and before the tray is
     * stored, so the app got an icon with no listeners, no MenuBarCreated event, and five
     * later menu-bar endpoints silently doing nothing.
     *
     * Upstream never meets any of them: its own `Window` and `MenuBar` declare typed
     * defaults for every property and serialise all of them on every call. This port omits
     * what the caller did not set, which is the better wire but only while every key the
     * runtime cannot do without is sent anyway. That is what this asserts.
     *
     * The mobile twin has had this direction since it found nine short payloads; the
     * desktop side had only the forward one, which is why two of the three defects above
     * were fixed by hand with nothing to stop the fourth.
     */
    public function testEveryKeyTheRuntimeReadsWithoutAGuardIsOneWeAlwaysSend(): void
    {
        $upstream = $this->upstreamRoutes();

        if ([] === $upstream) {
            self::markTestSkipped('Runtime sources not available.');
        }

        $checked = 0;
        $demanding = 0;
        $problems = [];

        foreach ($this->payloadsWeAlwaysSend() as $endpoint => $keys) {
            $route = $upstream[$endpoint] ?? null;

            // Unknown endpoint and whole-body forwarding are out of scope for the same
            // reasons as in the forward direction.
            if (null === $route || \in_array('*', $route['reads'], true)) {
                continue;
            }

            ++$checked;

            if ([] === $route['required']) {
                continue;
            }

            ++$demanding;
            $missing = array_values(array_diff($route['required'], $keys));

            if ([] !== $missing) {
                $problems[] = sprintf(
                    '%s omits [%s] — the handler hands it straight to a method call',
                    $endpoint,
                    implode(', ', $missing),
                );
            }
        }

        self::assertSame(self::EXPECTED_REVERSE_COMPARISONS, $checked, 'A different number of endpoints was compared than this test accounts for.');
        self::assertSame(self::ENDPOINTS_THAT_DEMAND_A_KEY, $demanding, 'The use-site parser has stopped finding what the runtime demands, so this direction is comparing nothing.');

        // The three defects that produced this check, named so a future refactor of either
        // parser cannot drop them without saying so.
        self::assertContains('windowButtonVisibility', $upstream['window/open']['required'], 'setWindowButtonVisibility() takes it unguarded; that key going missing was one of the three.');
        self::assertContains('label', $upstream['menu-bar/create']['required'], 'Tray.setTitle() takes it unguarded; that key going missing was one of the three.');
        self::assertContains('tooltip', $upstream['menu-bar/create']['required'], 'Tray.setToolTip() takes it unguarded; that key going missing was one of the three.');

        // zoomFactor was the fourth, and is the one that has since been fixed at the
        // source: upstream merged this repository's patch #137, so both use sites now go
        // through parseZoomFactor() and an absent value is 1 rather than NaN. It is no
        // longer a demand, which is why it cannot be asserted as one — but the guard
        // standing is what makes that true, so assert the guard instead. We keep sending
        // the key regardless; a consumer on an unpatched runtime still needs it.
        self::assertStringContainsString(
            'setZoomFactor(parseZoomFactor(zoomFactor))',
            (string) file_get_contents(__DIR__.'/../../upstream/np-desktop/resources/electron/electron-plugin/src/server/api/window.ts'),
            'The zoom factor is being read raw again, so it is a demand once more and belongs back in the count above.',
        );

        self::assertSame([], $problems, "A key the runtime reads with no default of its own has to be in every payload:\n".implode("\n", $problems));
    }

    public function testTheCallsExemptedFromTheOmittedKeyCheckStillSupplyTheirOwnDefault(): void
    {
        $runtime = __DIR__.'/../../upstream/np-desktop/resources/electron/electron-plugin/src';

        if (!is_dir($runtime)) {
            self::markTestSkipped('Runtime sources not available.');
        }

        foreach (self::CALLS_THAT_DEFAULT as $call => [$file, $guard]) {
            self::assertStringContainsString(
                $guard,
                (string) file_get_contents($runtime.'/'.$file),
                sprintf('%s no longer supplies its own default, so it cannot stay exempt from the omitted-key check.', $call),
            );
        }
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
        $found = $this->literalPayloads();

        foreach ($this->builderPayloads() as $endpoint => $keys) {
            foreach ($keys as $key) {
                $found[$endpoint][$key] = true;
            }
        }

        return array_map(static fn (array $keys): array => array_keys($keys), $found);
    }

    /**
     * The payload keys we send on *every* call to an endpoint, keyed by endpoint.
     *
     * Not the same question as payloadsWeSend(), and the difference is the whole of this
     * direction: `PendingMenuBar` has always had a `label()` setter, so `label` was in
     * that set the entire time it was omitted from three quarters of the create calls the
     * bundle can make. A builder key only counts here when the send path cannot avoid
     * writing it — assigned or defaulted at the top level of the posting method, of the
     * constructor, or in the property's own initialiser. Anything set inside a branch, or
     * only by a fluent setter, is optional by construction.
     *
     * A literal array handed to the transport contributes all of its top-level keys,
     * since there is nothing conditional about it.
     *
     * @return array<string, list<string>>
     */
    private function payloadsWeAlwaysSend(): array
    {
        $found = $this->literalPayloads();

        foreach ($this->sourceFiles(__DIR__.'/../src') as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match_all('/->post\(\s*\'([^\']+)\'\s*,\s*\$(?:this->)?payload/', $source, $posts, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($posts[1] as [$endpoint, $offset]) {
                $endpoint = $this->normalise($endpoint);
                $found[$endpoint] ??= [];

                $bodies = [$this->enclosingBody($source, $offset)];

                // `PendingWindow` takes its id as a constructor argument and writes it
                // straight into the payload, so the constructor is part of the send path.
                if (preg_match('/function __construct/', $source, $match, \PREG_OFFSET_CAPTURE)) {
                    $bodies[] = $this->enclosingBody($source, $match[0][1] + \strlen($match[0][0]));
                }

                foreach ($bodies as $body) {
                    foreach ($this->unconditionalKeys($body) as $key) {
                        $found[$endpoint][$key] = true;
                    }
                }

                if (preg_match('/(?:private|protected) array \$payload = (?=\[)/', $source, $match, \PREG_OFFSET_CAPTURE)) {
                    $literal = $this->balancedArray($source, $match[0][1] + \strlen($match[0][0]));

                    foreach ($this->topLevelKeys($literal) as $key) {
                        $found[$endpoint][$key] = true;
                    }
                }
            }
        }

        return array_map(static fn (array $keys): array => array_keys($keys), $found);
    }

    /**
     * Literal payload arrays handed straight to the transport, keyed by endpoint.
     *
     * @return array<string, array<string, true>>
     */
    private function literalPayloads(): array
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

        return $found;
    }

    /**
     * Payload keys written at the top level of a body, so no branch can skip them.
     *
     * `$payload['k'] =`, `$payload['k'] ??=` and a whole-array `$payload = ['k' => ...]`
     * all count; anything nested inside a brace does not, which is how
     * `PendingMenuBar::create()`'s conditional `url` stays out — the runtime destructures
     * it but only the popover branch reads it, and defaulting it for a bare tray icon
     * would make one depend on a resolvable base URL.
     *
     * @return list<string>
     */
    private function unconditionalKeys(string $body): array
    {
        $flat = '';
        $depth = 0;

        for ($i = 0, $length = \strlen($body); $i < $length; ++$i) {
            $char = $body[$i];

            if ('{' === $char) {
                ++$depth;
            }

            if (0 === $depth) {
                $flat .= $char;
            }

            if ('}' === $char) {
                --$depth;
            }
        }

        preg_match_all('/(?:\$this->payload|\$payload)\[\'([^\']+)\'\]\s*(?:\?\?)?=[^=]/', $flat, $keys);
        $found = $keys[1];

        if (preg_match('/(?:\$this->payload|\$payload)\s*=\s*(?=\[)/', $flat, $match, \PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1] + \strlen($match[0][0]);
            $found = [...$found, ...$this->topLevelKeys($this->balancedArray($flat, $start))];
        }

        return array_values(array_unique($found));
    }

    /** The brace-balanced body of the function enclosing `$offset`. */
    private function enclosingBody(string $source, int $offset): string
    {
        $declaration = strrpos(substr($source, 0, $offset), 'function ');

        if (false === $declaration) {
            return '';
        }

        $open = strpos($source, '{', $declaration);

        if (false === $open) {
            return '';
        }

        $depth = 0;

        for ($i = $open, $length = \strlen($source); $i < $length; ++$i) {
            if ('{' === $source[$i]) {
                ++$depth;
            } elseif ('}' === $source[$i]) {
                if (0 === --$depth) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        return substr($source, $open + 1);
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
        return array_map(static fn (array $route): array => $route['reads'], $this->upstreamRoutes());
    }

    /**
     * Each express handler, as one walk over the runtime's api modules.
     *
     * `reads` is every key the body touches; `required` is the subset it touches with
     * nothing of its own in between. One walk rather than two so the two directions of
     * this contract cannot come to disagree about which routes exist.
     *
     * @return array<string, array{reads: list<string>, required: list<string>}>
     */
    private function upstreamRoutes(): array
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
                // Wire key => the local name carrying it, for the use-site search below.
                // `event: customEvent` binds the key `event` to a local called
                // `customEvent`, and `event` is separately the name of every notification
                // callback's own argument — searching for the wire name there would read
                // `JSON.stringify(event)` as a demand for a key nothing demands.
                $locals = [];

                preg_match_all('/req\.(?:body|query|params)\.([A-Za-z_][A-Za-z0-9_]*)/', $body, $direct);

                foreach ($direct[1] as $key) {
                    $keys[] = $key;
                    // Read in place rather than bound to a name, so there is no local to
                    // look for at a use site. `appendWindowIdToUrl(req.body.url, id)` is
                    // one; the local `url` a line later is that call's *result*, and
                    // searching for the wire name would have credited it to the key.
                    $locals[$key] ??= null;
                }

                // `[^{}]*` rather than `[^}]*`: the loose version starts the capture at the
                // arrow function's own brace, so the group for `(req, res) => { ... const
                // { label } = req.body` came out as everything from the arrow onwards and
                // `res`, `sendStatus` and `const` all counted as keys the handler reads.
                preg_match_all('/\{([^{}]*)\}\s*=\s*req\.(body|query|params)/', $body, $destructured, \PREG_SET_ORDER);

                foreach ($destructured as $destructure) {
                    preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $destructure[1], $names);
                    $keys = [...$keys, ...$names[0]];

                    // A path parameter is carried by the URL, so asking whether it is in
                    // the payload compares the wrong thing.
                    if ('params' === $destructure[2]) {
                        continue;
                    }

                    foreach (explode(',', $destructure[1]) as $entry) {
                        // `{ key = fallback }` declares its own default, so the handler
                        // needs nothing from us; leave it out of the use-site search.
                        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?::\s*([A-Za-z_][A-Za-z0-9_]*)\s*)?$/', $entry, $names)) {
                            $locals[$names[1]] = $names[2] ?? $names[1];
                        }
                    }
                }

                // Whole-body forwarding only — `notifyLaravel('events', req.body)` or a
                // spread. `const { key } = req.body` is destructuring, and treating that as
                // "reads everything" would exempt most of the surface from this check,
                // which is how the first version of this test quietly compared ten
                // endpoints instead of fifty.
                if (preg_match('/\.\.\.req\.body|\(\s*req\.body\s*[,)]|(?<![}\s])\s*=\s*req\.body\b/', $body)) {
                    $keys[] = '*';
                }

                $arguments = $this->methodCallArguments($body);
                $required = [];

                foreach ($locals as $key => $local) {
                    if (null !== $local && isset($arguments[$local])) {
                        $required[] = $key;
                    }
                }

                $handlers[$endpoint]['reads'] = array_values(array_unique([...($handlers[$endpoint]['reads'] ?? []), ...$keys]));
                $handlers[$endpoint]['required'] = array_values(array_unique([...($handlers[$endpoint]['required'] ?? []), ...$required]));
            }
        }

        return $handlers;
    }

    /**
     * Identifiers handed whole to a method call, as `tray.setTitle(label)` does.
     *
     * The distinction that makes this direction of the contract worth anything. A bare
     * function call is the runtime's own code and can hold a default of its own:
     * `mergePreferences(webPreferences)` declares `= {}`, `buildMenu(contextMenu)` opens
     * with `if (contextMenu)`, `isLocalFile(sound)` with a `typeof` check. A method call
     * on something the runtime did not define is the boundary — past it is Electron,
     * whose generated bindings take a required `std::string` and throw on `undefined`.
     * So a key handed straight to one of those, with nothing in between, is a key that
     * has to be in every payload rather than only when the caller set it.
     *
     * Argument lists containing a nested call, array or object are skipped, because the
     * key is then an argument to something else: `showOpenDialogSync(trimOptions(...))`
     * reads nothing directly. The exception is a numeric cast, which is transparent for
     * this purpose — `setZoomFactor(parseFloat(zoomFactor))` turns an absent key into NaN
     * rather than defaulting it, and that is the same defect wearing a different hat.
     *
     * @return array<string, true>
     */
    private function methodCallArguments(string $body): array
    {
        $cast = '(?:parseInt|parseFloat|Number)\(\s*[A-Za-z_$][A-Za-z0-9_$]*\s*\)';

        if (!preg_match_all('/([A-Za-z_$][A-Za-z0-9_$]*)?\??\.\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(((?:'.$cast.'|[^(){}\[\]])*)\)/', $body, $calls, \PREG_SET_ORDER)) {
            return [];
        }

        $arguments = [];

        foreach ($calls as $call) {
            if (isset(self::CALLS_THAT_DEFAULT[$call[1].'.'.$call[2]])) {
                continue;
            }

            $list = (string) preg_replace('/(?:parseInt|parseFloat|Number)\(\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\)/', '$1', $call[3]);

            foreach (explode(',', $list) as $argument) {
                if (preg_match('/^\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*$/', $argument, $name)) {
                    $arguments[$name[1]] = true;
                }
            }
        }

        return $arguments;
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
