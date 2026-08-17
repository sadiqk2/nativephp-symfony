<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Testing;

use Native\Symfony\Desktop\Client\RuntimeNotAvailable;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Contract\Response;

/**
 * A runtime that isn't there: records every request the app makes and answers
 * with whatever the test scripted.
 *
 * The double sits at the transport seam rather than in front of each manager
 * (upstream Laravel fakes WindowManager, ChildProcess, Shell, … one class each).
 * One seam is faked instead of twenty because:
 *
 *  - the managers stay in the test. Their payload building — the part that has
 *    to match a 116-endpoint contract — is exercised rather than replaced, so a
 *    test that passes here is evidence about the wire, not about a stub;
 *  - all 116 endpoints are covered the day they are added, including the ones
 *    with no manager (menu templates, debug/*);
 *  - a manager fake has to re-declare every method and silently rots when the
 *    real one gains an argument.
 *
 * The cost is that assertions are phrased over endpoints and payloads, which is
 * lower-level than an app developer wants — that is what {@see RuntimeExpectations}
 * is for. Recording lives here, assertions live there, so this class can be
 * registered in a container that has no PHPUnit.
 *
 * Two contract behaviours are reproduced deliberately, because smoothing them
 * would let tests pass against a runtime that would fail:
 *
 *  - an unscripted endpoint answers a **bare 200 with no data** (CONTRACT.md §0
 *    shape 1: express sends the status phrase, which the real client normalises
 *    to "no data"). Reads therefore return their zero values unless scripted;
 *  - when the fake is marked unavailable, every call throws RuntimeNotAvailable
 *    exactly as the real client does outside the runtime — so `isAvailable()`
 *    guards can be tested.
 */
final class FakeRuntime implements ClientInterface
{
    /** @var list<RecordedCall> */
    private array $calls = [];

    /**
     * Endpoint pattern => responses still to hand out. The last entry repeats
     * once the script is exhausted; a test that polls an endpoint twice should
     * not have to script the second poll.
     *
     * @var array<string, non-empty-list<Response>>
     */
    private array $scripted = [];

    /** @var array<string, callable(RecordedCall): Response> */
    private array $handlers = [];

    /** @var array<string, array<string, mixed>> */
    private array $windows = [];

    /** The window `window/current` is scripted to report, so closing it can stop it doing so. */
    private ?string $currentWindow = null;

    private function __construct(private bool $available)
    {
    }

    /** A runtime that is present and answers. The normal case. */
    public static function available(): self
    {
        return new self(true);
    }

    /**
     * A process the runtime did not start: NATIVEPHP_API_URL is unset, so every
     * call throws. Use it to test the fallback path of code that also runs as an
     * ordinary web request.
     */
    public static function unavailable(): self
    {
        return new self(false);
    }

    // --- scripting: generic ---------------------------------------------------

    /**
     * @param string                           $endpoint Exact endpoint or a `*` wildcard pattern
     * @param array<string, mixed>|list<mixed> $data     Decoded JSON body the runtime would send
     */
    public function willReturn(string $endpoint, array $data, int $status = 200): self
    {
        return $this->willRespondWith($endpoint, new Response($status, $data));
    }

    /**
     * A status with no data — the runtime's most common reply, and the shape of
     * every error it reports without a body (404 from window/get, 410 from
     * child-process/get).
     */
    public function willReturnStatus(string $endpoint, int $status): self
    {
        return $this->willRespondWith($endpoint, new Response($status));
    }

    /** Consecutive calls to one endpoint get consecutive responses; the last repeats. */
    public function willRespondWith(string $endpoint, Response ...$responses): self
    {
        if ([] === $responses) {
            throw new \InvalidArgumentException('Script at least one response for '.$endpoint.'.');
        }

        $this->scripted[$endpoint] = array_values($responses);

        return $this;
    }

    /**
     * Answer from a callback — for replies that depend on the request, such as
     * echoing back a value the app just sent.
     *
     * @param callable(RecordedCall): Response $handler
     */
    public function willRespondUsing(string $endpoint, callable $handler): self
    {
        $this->handlers[$endpoint] = $handler;

        return $this;
    }

    // --- scripting: the user's answer to a dialog -----------------------------
    //
    // Dialogs are the case that cannot be tested any other way: they block the
    // runtime's event loop and their return value *is* a human decision
    // (CONTRACT.md §6). Everything below is `willReturn` with the runtime's exact
    // response shape filled in, so tests read as the user's behaviour.

    /** The user selected these paths in an open dialog. */
    public function userPicksFiles(string ...$paths): self
    {
        return $this->willReturn('dialog/open', ['result' => array_values($paths)]);
    }

    /**
     * The user dismissed the open dialog.
     *
     * The runtime sends `{result: undefined}`, which arrives as a *missing* key
     * rather than an empty list — scripting `['result' => []]` would test a shape
     * the runtime never sends.
     */
    public function userCancelsFileSelection(): self
    {
        return $this->willReturn('dialog/open', []);
    }

    /** The user chose a destination in a save dialog. */
    public function userSavesFileAs(string $path): self
    {
        return $this->willReturn('dialog/save', ['result' => $path]);
    }

    public function userCancelsSave(): self
    {
        return $this->willReturn('dialog/save', []);
    }

    /**
     * The user clicked the button at this index in an alert.
     *
     * @param int $index Index into the `buttons` list the app sent
     */
    public function userClicksAlertButton(int $index): self
    {
        return $this->willReturn('alert/message', ['result' => $index]);
    }

    /**
     * The user accepted a DialogManager::confirm().
     *
     * confirm() builds [confirm, cancel] and reads index 0 as yes, so the two
     * helpers below are just that convention named.
     */
    public function userConfirms(): self
    {
        return $this->userClicksAlertButton(0);
    }

    public function userDeclines(): self
    {
        return $this->userClicksAlertButton(1);
    }

    // --- scripting: windows --------------------------------------------------

    /**
     * A window the runtime knows about: served from `window/get/{id}` and
     * included in `window/all`.
     *
     * @param array<string, mixed> $attributes Any subset of the runtime's WindowData;
     *                                         unset fields take Window::fromRuntime's zero values
     */
    public function windowIs(string $id, array $attributes = []): self
    {
        $this->windows[$id] = ['id' => $id, ...$attributes];

        $this->willReturn("window/get/{$id}", $this->windows[$id]);
        $this->willReturn('window/all', array_values($this->windows));

        return $this;
    }

    /**
     * The window the current request came from, as `window/current` reports it.
     *
     * @param array<string, mixed> $attributes
     */
    public function currentWindowIs(string $id, array $attributes = []): self
    {
        $this->windowIs($id, $attributes);
        $this->currentWindow = $id;

        return $this->willReturn('window/current', $this->windows[$id]);
    }

    /**
     * `window/current` fails, which is its documented normal behaviour when the
     * app is backgrounded: the runtime dereferences getFocusedWindow().id with no
     * null guard and answers 500 (CONTRACT.md §1). Any code that reads the
     * current window has to survive this.
     */
    public function noCurrentWindow(): self
    {
        return $this->willReturnStatus('window/current', 500);
    }

    /** `window/get/{id}` answers 404 with the status phrase as its body. */
    public function windowDoesNotExist(string $id): self
    {
        $known = isset($this->windows[$id]);

        unset($this->windows[$id]);

        // window/all has to be rewritten too. Upstream reads both endpoints from
        // the same state.windows map, so they cannot disagree there — leaving the
        // old list scripted meant a closed window still appeared in all(), and a
        // "only main is left open" assertion passed against an app that would see
        // otherwise live.
        //
        // Only for a window this fake was actually told about, though: saying a
        // never-registered id does not exist is a statement about that id alone,
        // and rewriting the list there would silently discard a window/all a test
        // had scripted by hand.
        if ($known) {
            $this->willReturn('window/all', array_values($this->windows));
        }

        // And it cannot still be the current one. Closing the focused window leaves the
        // runtime dereferencing getFocusedWindow().id with no null guard, which is the 500
        // noCurrentWindow() scripts (CONTRACT.md §1) — whereas leaving the old script in
        // place had window/current hand back a window that window/get now 404s, a state the
        // real runtime cannot be in.
        if ($id === $this->currentWindow) {
            $this->currentWindow = null;
            $this->willReturnStatus('window/current', 500);
        }

        return $this->willReturnStatus("window/get/{$id}", 404);
    }

    // --- inspection -----------------------------------------------------------

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function get(string $endpoint, array $query = [], ?int $timeout = null): Response
    {
        return $this->respond('GET', $endpoint, $query);
    }

    public function post(string $endpoint, array $data = [], ?int $timeout = null): Response
    {
        return $this->respond('POST', $endpoint, $data);
    }

    public function delete(string $endpoint, array $data = [], ?int $timeout = null): Response
    {
        return $this->respond('DELETE', $endpoint, $data);
    }

    /** @return list<RecordedCall> Everything the app sent, in order. */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param string $pattern Exact endpoint or a `*` wildcard pattern
     *
     * @return list<RecordedCall>
     */
    public function callsTo(string $pattern): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (RecordedCall $call): bool => $call->matchesEndpoint($pattern),
        ));
    }

    /** Forget the call log but keep the script — useful between phases of one test. */
    public function forgetCalls(): void
    {
        $this->calls = [];
    }

    /** @param array<array-key, mixed> $payload */
    private function respond(string $method, string $endpoint, array $payload): Response
    {
        // Before recording: an unavailable runtime never received the call, and a
        // test asserting on the throw should not also see a phantom request.
        if (!$this->available) {
            throw RuntimeNotAvailable::forEndpoint($endpoint);
        }

        $call = new RecordedCall(\count($this->calls) + 1, $method, $endpoint, $payload);
        $this->calls[] = $call;

        if (null !== $pattern = $this->match($endpoint, $this->handlers)) {
            return ($this->handlers[$pattern])($call);
        }

        if (null === $pattern = $this->match($endpoint, $this->scripted)) {
            // A message box answers with the index of the button the user clicked,
            // and DialogManager refuses to invent one — a missing result there means
            // the app cannot know what was chosen, and it throws rather than guess.
            // Under a fake there is no user, so the fake supplies the answer; the
            // question is which one.
            //
            // Dismissal, not confirmation. Button 0 is the *confirming* button, so
            // defaulting to it made an unscripted `confirm()` answer yes — and the
            // test that then wrongly passes is the most valuable one anybody writes
            // here, "deleting requires confirmation". cancelId is what Electron
            // returns for Escape and the window close button, and confirm() sends
            // cancelId: 1 precisely so those mean no. Every other unscripted dialog
            // already fails safe this way: dialog/open degrades to cancelled and
            // dialog/save to null.
            if ('alert/message' === $endpoint) {
                $cancelId = $call->payload['cancelId'] ?? 0;

                return new Response(200, ['result' => \is_int($cancelId) ? $cancelId : 0]);
            }

            // CONTRACT.md §0 shape 1: `res.sendStatus(200)`, which the real client
            // reads as a success carrying no data.
            return new Response(200);
        }

        return \count($this->scripted[$pattern]) > 1
            ? array_shift($this->scripted[$pattern])
            : $this->scripted[$pattern][0];
    }

    /**
     * Exact patterns win over wildcards, and among wildcards the most specific
     * (longest) one wins — otherwise `window/*` would shadow `window/get/main`
     * and the shadowing would depend on declaration order.
     *
     * @param array<string, mixed> $candidates
     */
    private function match(string $endpoint, array $candidates): ?string
    {
        if (isset($candidates[$endpoint])) {
            return $endpoint;
        }

        $matches = array_filter(
            array_keys($candidates),
            static fn (string $pattern): bool => str_contains($pattern, '*')
                && (new RecordedCall(0, '', $endpoint))->matchesEndpoint($pattern),
        );

        usort($matches, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $matches[0] ?? null;
    }
}
