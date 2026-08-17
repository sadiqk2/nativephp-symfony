<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Contract\Response;
use Native\Symfony\System\DebugLogger;
use Native\Symfony\Testing\FakeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * The devtools logger, and the loop it used to be able to close.
 *
 * Untested until a packaged build made it matter. `/api/debug` is mounted only when
 * `NODE_ENV === 'development'`, so in a build the post is unrouted — express answers with
 * its default HTML page, not the status phrase — and `Client` reports that it could not
 * read the reply through the `native` channel. Wire this logger onto that channel, which is
 * what the docs suggest, and the report posts a record, which 404s, which reports.
 */
final class DebugLoggerTest extends TestCase
{
    public function testItForwardsARecordToTheRuntime(): void
    {
        $runtime = FakeRuntime::available();

        (new DebugLogger($runtime))->error('boom', ['id' => 7]);

        $calls = $runtime->callsTo('debug/log');

        self::assertCount(1, $calls);
        self::assertSame('error', $calls[0]->payload['level']);
        self::assertSame('boom', $calls[0]->payload['message']);
        self::assertSame(['id' => 7], $calls[0]->payload['context']);
    }

    public function testAnUnavailableRuntimeIsNotEvenAttempted(): void
    {
        $runtime = FakeRuntime::unavailable();

        (new DebugLogger($runtime))->info('nothing to see');

        self::assertSame([], $runtime->calls());
    }

    public function testALoggingRuntimeCannotDriveThisLoggerInACircle(): void
    {
        // The client that answers this post logs, as the real one does when it cannot
        // read a reply. Without the re-entry guard the logger posts again from inside its
        // own post, and every turn is a blocking HTTP request — so the symptom is not a
        // stack overflow but a request that never returns.
        $runtime = FakeRuntime::available();
        $logger = new DebugLogger($runtime);

        $runtime->willRespondUsing('debug/log', static function () use ($logger): Response {
            $logger->error('Runtime returned non-JSON for POST debug/log');

            return new Response(200);
        });

        $logger->error('the first record');

        self::assertCount(1, $runtime->callsTo('debug/log'), 'The nested record must not become a second post.');
    }

    public function testItStopsPostingOnceTheRuntimeSaysThereIsNoDebugRoute(): void
    {
        // A packaged app: the routes are mounted at boot or never, so a 404 is permanent
        // and every further record would be a blocking round trip for nothing.
        $runtime = FakeRuntime::available()->willReturnStatus('debug/log', 404);
        $logger = new DebugLogger($runtime);

        $logger->warning('first');
        $logger->warning('second');
        $logger->warning('third');

        self::assertCount(1, $runtime->callsTo('debug/log'));
    }
}
