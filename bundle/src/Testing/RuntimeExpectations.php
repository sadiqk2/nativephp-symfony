<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Testing;

use PHPUnit\Framework\Assert;

/**
 * Assertions about what an app asked the runtime to do.
 *
 * Every helper is phrased as an intent an app developer already has in mind
 * ("a window with this id was opened", "no notification was sent") and resolves
 * to an endpoint-and-payload predicate over {@see FakeRuntime}'s call log. When
 * one fails it prints what was expected *and* the whole call log, because the
 * question a red test has to answer is "then what did it do instead?".
 *
 * Honesty about naming: these assert **requests**, never outcomes. The runtime
 * ignores unknown window ids silently, `menu-bar/*` and `context/*` answer 200
 * before doing any work at all (CONTRACT.md §0 shape 3), and no endpoint reports
 * whether the user saw anything. `assertWindowOpened('main')` therefore means
 * "the app asked for window 'main'", which is the strongest claim any test
 * without an Electron process can make. The one place a real outcome is
 * observable is a dialog's return value, and that is the app's own code to
 * assert on, not this class's.
 *
 * Requires phpunit/phpunit, which an app already has wherever this is used.
 */
final class RuntimeExpectations
{
    /** Endpoints that show something modal and blocking (CONTRACT.md §6). */
    private const DIALOG_ENDPOINTS = [
        'dialog/open',
        'dialog/save',
        'alert/message',
        'alert/error',
        // Blocks the event loop until the user acts, exactly like the four above —
        // CONTRACT.md §0 lists it alongside them. Without it, "this path never
        // blocks on the user" passed against code that fires a TouchID prompt.
        'system/prompt-touch-id',
    ];

    /** All four ways a child process can be started; every one is keyed by alias. */
    private const START_ENDPOINTS = [
        'child-process/start',
        'child-process/start-php',
        'child-process/start-node',
        'child-process/restart',
    ];

    public function __construct(private readonly FakeRuntime $runtime)
    {
    }

    // --- windows --------------------------------------------------------------

    /**
     * @param string|null          $id      Null asserts that *some* window was opened
     * @param array<string, mixed> $options Further window/open keys that must match,
     *                                      e.g. ['width' => 400, 'frame' => false]
     */
    public function assertWindowOpened(?string $id = null, array $options = []): void
    {
        $this->assertRequested(
            $this->describeWindowIntent('open a window', $id, $options),
            'window/open',
            $this->matchingId($id, $options),
        );
    }

    public function assertNoWindowOpened(?string $id = null): void
    {
        $this->assertNotRequested(
            $this->describeWindowIntent('open no window', $id),
            ['window/open'],
            $this->matchingId($id),
        );
    }

    public function assertWindowClosed(?string $id = null): void
    {
        $this->assertRequested(
            $this->describeWindowIntent('close a window', $id),
            'window/close',
            $this->matchingId($id),
        );
    }

    /**
     * The "did my guard actually hold?" assertion — the reason a close needs a
     * negative form at all is that an unknown id is a silent no-op runtime-side,
     * so a wrongly-guarded close looks identical to a correct one at runtime.
     */
    public function assertNoWindowClosed(?string $id = null): void
    {
        $this->assertNotRequested(
            $this->describeWindowIntent('close no window', $id),
            ['window/close'],
            $this->matchingId($id),
        );
    }

    public function assertWindowShown(?string $id = null): void
    {
        $this->assertRequested($this->describeWindowIntent('show a window', $id), 'window/show', $this->matchingId($id));
    }

    public function assertWindowHidden(?string $id = null): void
    {
        $this->assertRequested($this->describeWindowIntent('hide a window', $id), 'window/hide', $this->matchingId($id));
    }

    public function assertWindowResized(int $width, int $height, ?string $id = null): void
    {
        $this->assertRequested(
            sprintf('resize window %s to %dx%d', $this->quoteId($id), $width, $height),
            'window/resize',
            $this->matchingId($id, ['width' => $width, 'height' => $height]),
        );
    }

    public function assertWindowTitled(string $title, ?string $id = null): void
    {
        $this->assertRequested(
            sprintf('set the title of window %s to "%s"', $this->quoteId($id), $title),
            'window/title',
            $this->matchingId($id, ['title' => $title]),
        );
    }

    /**
     * @param string $url Absolute, or the path suffix — WindowManager::navigate()
     *                    resolves relative URLs against the current request before
     *                    sending, and a test should not have to spell that out
     */
    public function assertWindowNavigatedTo(string $url, ?string $id = null): void
    {
        $this->assertRequested(
            sprintf('navigate window %s to "%s"', $this->quoteId($id), $url),
            'window/url',
            function (RecordedCall $call) use ($url, $id): bool {
                $sent = $call->payload['url'] ?? null;

                if (!($this->matchingId($id))($call) || !\is_string($sent)) {
                    return false;
                }

                if ($sent === $url) {
                    return true;
                }

                // A relative expectation has to be compared against the path, not
                // matched as a suffix: UrlResolver absolutises what the app sends, so
                // some tolerance is needed, but str_ends_with also accepted
                // /admin/reports for an expected /reports — certifying the exact
                // routing bug the assertion exists to catch.
                if (!str_starts_with($url, '/')) {
                    return false;
                }

                $expected = parse_url($url);
                $actual = parse_url($sent);

                return \is_array($expected) && \is_array($actual)
                    && ($expected['path'] ?? null) === ($actual['path'] ?? null)
                    && ($expected['query'] ?? null) === ($actual['query'] ?? null);
            },
        );
    }

    // --- dialogs --------------------------------------------------------------

    /**
     * A file-open dialog was shown.
     *
     * @param array<string, mixed> $options dialog/open keys that must match, e.g.
     *                                      ['properties' => ['openDirectory']]
     */
    public function assertFileDialogShown(array $options = []): void
    {
        $this->assertRequested(
            'show a file-open dialog'.$this->describeOptions($options),
            'dialog/open',
            [] === $options ? null : static fn (RecordedCall $c): bool => $c->payloadContains($options),
        );
    }

    /**
     * The dialog was made modal to a specific window.
     *
     * Worth its own helper because the payload key (`windowReference`) does not
     * look like the intent, and because getting it wrong is invisible: an id the
     * runtime cannot resolve silently degrades to an app-modal dialog.
     */
    public function assertFileDialogShownAttachedTo(string $windowId): void
    {
        $this->assertRequested(
            sprintf('show a file-open dialog attached to window "%s"', $windowId),
            'dialog/open',
            static fn (RecordedCall $c): bool => $c->payloadContains(['windowReference' => $windowId]),
        );
    }

    /** @param array<string, mixed> $options */
    public function assertSaveDialogShown(array $options = []): void
    {
        $this->assertRequested(
            'show a save dialog'.$this->describeOptions($options),
            'dialog/save',
            [] === $options ? null : static fn (RecordedCall $c): bool => $c->payloadContains($options),
        );
    }

    /** No dialog, alert or error box at all — nothing that would block the runtime. */
    public function assertNoDialogShown(): void
    {
        $this->assertNotRequested('show no dialog or alert', self::DIALOG_ENDPOINTS);
    }

    public function assertAlerted(string $message): void
    {
        $this->assertRequested(
            sprintf('show an alert saying "%s"', $message),
            'alert/message',
            static fn (RecordedCall $c): bool => $c->payloadContains(['message' => $message]),
        );
    }

    public function assertErrorBoxShown(?string $title = null): void
    {
        $this->assertRequested(
            null === $title ? 'show a native error box' : sprintf('show a native error box titled "%s"', $title),
            'alert/error',
            null === $title ? null : static fn (RecordedCall $c): bool => $c->payloadContains(['title' => $title]),
        );
    }

    // --- notifications --------------------------------------------------------

    public function assertNotificationSent(?string $title = null, ?string $body = null): void
    {
        $expected = array_filter(['title' => $title, 'body' => $body], static fn (?string $v): bool => null !== $v);

        $this->assertRequested(
            'send a notification'.$this->describeOptions($expected),
            'notification',
            [] === $expected ? null : static fn (RecordedCall $c): bool => $c->payloadContains($expected),
        );
    }

    public function assertNoNotificationSent(): void
    {
        $this->assertNotRequested('send no notification', ['notification']);
    }

    // --- child processes ------------------------------------------------------

    /**
     * A child process was started under this alias, by any of start/start-php/
     * start-node/restart — which one is an implementation detail of the manager
     * method chosen, while the alias is the app's own identifier.
     *
     * @param list<string>|null $cmd The exact command, when it matters
     */
    public function assertProcessStarted(string $alias, ?array $cmd = null): void
    {
        $expected = ['alias' => $alias] + (null === $cmd ? [] : ['cmd' => $cmd]);

        $this->assertRequested(
            sprintf('start the child process "%s"', $alias).(null === $cmd ? '' : ' running '.implode(' ', $cmd)),
            self::START_ENDPOINTS,
            static fn (RecordedCall $c): bool => $c->payloadContains($expected),
        );
    }

    public function assertProcessNotStarted(?string $alias = null): void
    {
        $this->assertNotRequested(
            null === $alias ? 'start no child process' : sprintf('not start the child process "%s"', $alias),
            self::START_ENDPOINTS,
            null === $alias ? null : static fn (RecordedCall $c): bool => $c->payloadContains(['alias' => $alias]),
        );
    }

    public function assertProcessStopped(string $alias): void
    {
        $this->assertRequested(
            sprintf('stop the child process "%s"', $alias),
            'child-process/stop',
            static fn (RecordedCall $c): bool => $c->payloadContains(['alias' => $alias]),
        );
    }

    /**
     * A message was pushed over the utilityProcess IPC channel.
     *
     * The runtime answers 200 for an unknown alias, so this asserts the send and
     * nothing about delivery.
     */
    public function assertMessageSentToProcess(string $alias, mixed $message = null): void
    {
        $expected = ['alias' => $alias] + (null === $message ? [] : ['message' => $message]);

        $this->assertRequested(
            sprintf('send a message to the child process "%s"', $alias),
            'child-process/message',
            static fn (RecordedCall $c): bool => $c->payloadContains($expected),
        );
    }

    // --- app ------------------------------------------------------------------

    public function assertQuitRequested(): void
    {
        $this->assertRequested('quit the application', 'app/quit');
    }

    public function assertNoQuitRequested(): void
    {
        $this->assertNotRequested('not quit the application', ['app/quit', 'app/relaunch']);
    }

    // --- escape hatches -------------------------------------------------------
    //
    // 116 endpoints will always outrun the named helpers above. These keep an app
    // able to assert on the rest without dropping to `$fake->calls()`.

    /**
     * @param string                    $endpoint Exact endpoint or a `*` wildcard pattern
     * @param array<array-key, mixed>|null $payload Keys and values the request must contain
     */
    public function assertCalled(string $endpoint, ?array $payload = null): void
    {
        $this->assertRequested(
            sprintf('call %s', $endpoint).(null === $payload ? '' : $this->describeOptions($payload)),
            $endpoint,
            null === $payload ? null : static fn (RecordedCall $c): bool => $c->payloadContains($payload),
        );
    }

    public function assertNotCalled(string $endpoint): void
    {
        $this->assertNotRequested(sprintf('not call %s', $endpoint), [$endpoint]);
    }

    public function assertCalledTimes(int $times, string $endpoint): void
    {
        $actual = \count($this->runtime->callsTo($endpoint));

        $this->verify(
            $times === $actual,
            sprintf(
                "Expected the app to call %s %d time(s), but it called it %d time(s).\n%s",
                $endpoint,
                $times,
                $actual,
                $this->log(),
            ),
        );
    }

    /** The app touched the runtime not at all — the assertion for a guarded path. */
    public function assertNothingSent(): void
    {
        $calls = $this->runtime->calls();

        $this->verify(
            [] === $calls,
            sprintf(
                "Expected the app to send nothing to the runtime, but it sent %d call(s):\n%s",
                \count($calls),
                $this->format($calls),
            ),
        );
    }

    // --- machinery ------------------------------------------------------------

    /**
     * @param string|list<string>            $endpoints
     * @param (callable(RecordedCall): bool)|null $predicate
     */
    private function assertRequested(string $intent, string|array $endpoints, ?callable $predicate = null): void
    {
        $candidates = $this->candidates($endpoints);
        $matches = null === $predicate ? $candidates : array_filter($candidates, $predicate);

        $this->verify(
            [] !== $matches,
            sprintf(
                "Expected the app to ask the runtime to %s.\n%s\n%s",
                $intent,
                [] === $candidates
                    ? sprintf('It never called %s.', implode(' or ', (array) $endpoints))
                    : 'It called those endpoints, but no call matched.',
                $this->log(),
            ),
        );
    }

    /**
     * @param list<string>                        $endpoints
     * @param (callable(RecordedCall): bool)|null $predicate
     */
    private function assertNotRequested(string $intent, array $endpoints, ?callable $predicate = null): void
    {
        $matches = $this->candidates($endpoints);

        if (null !== $predicate) {
            $matches = array_filter($matches, $predicate);
        }

        $this->verify(
            [] === $matches,
            sprintf(
                "Expected the app to %s, but it made %d such call(s):\n%s\n%s",
                $intent,
                \count($matches),
                $this->format($matches),
                $this->log(),
            ),
        );
    }

    /**
     * @param string|list<string> $endpoints
     *
     * @return list<RecordedCall>
     */
    private function candidates(string|array $endpoints): array
    {
        $calls = [];

        foreach ((array) $endpoints as $endpoint) {
            foreach ($this->runtime->callsTo($endpoint) as $call) {
                $calls[$call->sequence] = $call;
            }
        }

        ksort($calls);

        return array_values($calls);
    }

    /**
     * A single funnel for every assertion, for two reasons: a passing helper
     * still has to register an assertion with PHPUnit (the bundle's suite runs
     * with failOnRisky, and so should an app's), and a failing one has to print
     * our message and nothing else — Assert::fail() prints exactly what it is
     * given, where assertTrue() would append "Failed asserting that false is true".
     */
    private function verify(bool $satisfied, string $message): void
    {
        Assert::assertTrue(true);

        if (!$satisfied) {
            Assert::fail($message);
        }
    }

    /** @param array<string, mixed> $options */
    private function describeOptions(array $options): string
    {
        if ([] === $options) {
            return '';
        }

        $pairs = [];

        foreach ($options as $key => $value) {
            $pairs[] = sprintf('%s=%s', $key, json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        return ' with '.implode(', ', $pairs);
    }

    /** @param array<string, mixed> $options */
    private function describeWindowIntent(string $verb, ?string $id, array $options = []): string
    {
        return $verb.(null === $id ? '' : sprintf(' with id "%s"', $id)).$this->describeOptions($options);
    }

    private function quoteId(?string $id): string
    {
        return null === $id ? '(any)' : sprintf('"%s"', $id);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return callable(RecordedCall): bool
     */
    private function matchingId(?string $id, array $options = []): callable
    {
        $expected = (null === $id ? [] : ['id' => $id]) + $options;

        return static fn (RecordedCall $call): bool => [] === $expected || $call->payloadContains($expected);
    }

    private function log(): string
    {
        $calls = $this->runtime->calls();

        return [] === $calls
            ? 'No runtime calls were recorded at all.'
            : sprintf("Recorded runtime calls (%d):\n%s", \count($calls), $this->format($calls));
    }

    /** @param iterable<RecordedCall> $calls */
    private function format(iterable $calls): string
    {
        $lines = [];

        foreach ($calls as $call) {
            $lines[] = '  '.$call->describe();
        }

        return [] === $lines ? '  (none)' : implode("\n", $lines);
    }
}
