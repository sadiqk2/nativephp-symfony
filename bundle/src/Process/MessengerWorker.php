<?php

declare(strict_types=1);

namespace Native\Symfony\Process;

/**
 * Runs `messenger:consume` as a runtime-supervised child process.
 *
 * This is the Symfony counterpart to Laravel's QueueWorker, which starts
 * `queue:work` the same way. The argument names are the only real difference:
 *
 *   Laravel            Symfony Messenger
 *   --memory=128       --memory-limit=128M
 *   --timeout=60       --time-limit=60
 *   --sleep=3          --sleep=3
 *   --queue=a,b        <transport> <transport>   (positional)
 *
 * `persistent: true` puts the runtime's watchdog in charge of restarts, which is
 * what you want for a long-lived consumer: a worker that exits on --memory-limit
 * comes straight back.
 *
 * `handlesOwnShutdown: true` means the runtime SIGTERMs the worker alone rather
 * than tree-killing it, so Messenger can finish the message it is handling. Without
 * it, a tree-kill can drop an in-flight message.
 */
final class MessengerWorker
{
    public function __construct(private readonly ChildProcessManager $processes)
    {
    }

    /**
     * @param list<string> $transports  Transport names; empty consumes the default
     * @param int          $memoryLimit In megabytes
     * @param int          $timeLimit   Seconds before the worker exits for a restart
     */
    public function up(
        string $alias = 'messenger',
        array $transports = [],
        int $memoryLimit = 128,
        int $timeLimit = 3600,
        int $sleep = 1,
        int $limit = 0,
    ): ProcessHandle {
        $arguments = ['messenger:consume', ...array_values($transports)];

        $arguments[] = "--memory-limit={$memoryLimit}M";
        $arguments[] = "--time-limit={$timeLimit}";
        $arguments[] = "--sleep={$sleep}";
        $arguments[] = '--no-interaction';

        if ($limit > 0) {
            $arguments[] = "--limit={$limit}";
        }

        return $this->processes->console(
            alias: $this->alias($alias),
            arguments: $arguments,
            persistent: true,
            handlesOwnShutdown: true,
            // The worker must be allowed at least as much memory as the limit it
            // is told to watch for, or PHP fatals before Messenger can exit cleanly.
            iniSettings: ['memory_limit' => ($memoryLimit * 2).'M'],
        );
    }

    public function down(string $alias = 'messenger'): void
    {
        $this->processes->stop($this->alias($alias));
    }

    public function status(string $alias = 'messenger'): ?ProcessHandle
    {
        return $this->processes->get($this->alias($alias));
    }

    /** Namespaced so a worker cannot collide with an app's own process aliases. */
    private function alias(string $alias): string
    {
        return 'messenger_'.$alias;
    }
}
