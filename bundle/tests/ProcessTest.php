<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Process\ChildProcessManager;
use Native\Symfony\Process\MessengerWorker;
use PHPUnit\Framework\TestCase;

final class ProcessTest extends TestCase
{
    public function testPidIsNullOnTheSynchronousStartResponse(): void
    {
        // The runtime returns before Electron's `spawn` fires, so the pid on this
        // path is always null. Reading it here instead of from ProcessSpawned is
        // the classic mistake.
        $client = new FakeClient();
        $client->willReturn('child-process/start', ['pid' => null, 'settings' => ['cmd' => ['ls']]]);

        $handle = (new ChildProcessManager($client))->start('lister', ['ls']);

        self::assertNull($handle->pid);
        self::assertFalse($handle->isRunning());
        self::assertSame(['ls'], $handle->cmd);
    }

    public function testConsolePrependsThePatchedCliName(): void
    {
        $client = new FakeClient();

        (new ChildProcessManager($client))->console('worker', ['app:work', '--once']);

        self::assertSame('child-process/start-php', $client->lastCall()['endpoint']);
        self::assertSame(
            ['bin/console', 'app:work', '--once'],
            $client->lastCall()['data']['cmd'],
        );
    }

    public function testNullOptionsAreStrippedFromTheStartPayload(): void
    {
        $client = new FakeClient();

        (new ChildProcessManager($client))->start('x', ['ls']);

        self::assertArrayNotHasKey('cwd', $client->lastCall()['data']);
    }

    public function testGetReturnsNullOn410(): void
    {
        $client = new FakeClient();
        $client->willReturnStatus('child-process/get/ghost', 410);

        self::assertNull((new ChildProcessManager($client))->get('ghost'));
    }

    public function testAllSkipsNonArrayEntries(): void
    {
        $client = new FakeClient();
        $client->willReturn('child-process', [
            'a' => ['pid' => 11, 'settings' => ['persistent' => true]],
            'b' => 'nonsense',
        ]);

        $all = (new ChildProcessManager($client))->all();

        self::assertCount(1, $all);
        self::assertSame(11, $all['a']->pid);
        self::assertTrue($all['a']->isPersistent());
    }

    public function testMessengerWorkerUsesMessengerArgumentNamesNotLaravels(): void
    {
        $client = new FakeClient();

        (new MessengerWorker(new ChildProcessManager($client)))
            ->up(transports: ['async', 'failed'], memoryLimit: 256, timeLimit: 900, sleep: 2);

        $cmd = $client->lastCall()['data']['cmd'];

        self::assertSame([
            'bin/console', 'messenger:consume', 'async', 'failed',
            '--memory-limit=256M', '--time-limit=900', '--sleep=2', '--no-interaction',
        ], $cmd);
    }

    public function testMessengerWorkerIsSupervisedAndShutsItselfDown(): void
    {
        $client = new FakeClient();

        (new MessengerWorker(new ChildProcessManager($client)))->up();

        $data = $client->lastCall()['data'];

        // persistent: the runtime restarts a worker that exits on --memory-limit.
        self::assertTrue($data['persistent']);
        // handlesOwnShutdown: a plain SIGTERM rather than a tree-kill, so Messenger
        // can finish the message in flight.
        self::assertTrue($data['handlesOwnShutdown']);
    }

    public function testWorkerGetsMoreMemoryThanTheLimitItWatchesFor(): void
    {
        // Otherwise PHP fatals before Messenger can notice the limit and exit.
        $client = new FakeClient();

        (new MessengerWorker(new ChildProcessManager($client)))->up(memoryLimit: 128);

        self::assertSame('256M', $client->lastCall()['data']['iniSettings']['memory_limit']);
    }

    public function testWorkerAliasesAreNamespaced(): void
    {
        $client = new FakeClient();
        $worker = new MessengerWorker(new ChildProcessManager($client));

        $worker->up('imports');
        self::assertSame('messenger_imports', $client->lastCall()['data']['alias']);

        $worker->down('imports');
        self::assertSame('messenger_imports', $client->lastCall()['data']['alias']);
    }
}
