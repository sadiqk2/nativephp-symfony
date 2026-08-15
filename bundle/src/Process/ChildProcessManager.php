<?php

declare(strict_types=1);

namespace Native\Symfony\Process;

use Native\Symfony\Contract\ClientInterface;

/**
 * Runtime-managed child processes — 8 endpoints.
 *
 * These run as Electron utilityProcesses, which matters: the runtime supervises
 * them, restarts the persistent ones, and tears them all down on quit with a
 * 12-second drain window. A process started here outlives the request that
 * started it, unlike anything spawned with symfony/process from a web request.
 *
 * Every start is **idempotent by alias** — starting an alias that already exists
 * returns the existing handle and starts nothing.
 */
final class ChildProcessManager
{
    /** The CLI name the runtime was patched to use; see Runtime\RuntimePatcher. */
    public const CLI = 'bin/console';

    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * Start an arbitrary command under Electron's bundled Node.
     *
     * @param list<string>          $cmd
     * @param array<string, string> $env
     */
    public function start(
        string $alias,
        array $cmd,
        ?string $cwd = null,
        array $env = [],
        bool $persistent = false,
        bool $handlesOwnShutdown = false,
        int $spawnTimeout = 30000,
    ): ProcessHandle {
        return $this->post('child-process/start', $alias, [
            'alias' => $alias,
            'cmd' => array_values($cmd),
            'cwd' => $cwd,
            'env' => $env,
            'persistent' => $persistent,
            'handlesOwnShutdown' => $handlesOwnShutdown,
            'spawnTimeout' => $spawnTimeout,
        ]);
    }

    /**
     * Start a PHP command. The runtime prepends its own PHP binary and ini flags,
     * and injects the full NATIVEPHP_* environment — including the API URL and
     * secret, so the child can call back into the runtime.
     *
     * @param list<string>          $cmd         Arguments *after* the php binary
     * @param array<string, string> $env
     * @param array<string, scalar> $iniSettings Merged over the runtime's defaults
     */
    public function php(
        string $alias,
        array $cmd,
        ?string $cwd = null,
        array $env = [],
        bool $persistent = false,
        bool $handlesOwnShutdown = false,
        array $iniSettings = [],
        int $spawnTimeout = 30000,
    ): ProcessHandle {
        return $this->post('child-process/start-php', $alias, [
            'alias' => $alias,
            'cmd' => array_values($cmd),
            'cwd' => $cwd,
            'env' => $env,
            'persistent' => $persistent,
            'handlesOwnShutdown' => $handlesOwnShutdown,
            'iniSettings' => $iniSettings,
            'spawnTimeout' => $spawnTimeout,
        ]);
    }

    /**
     * Run a Symfony console command as a child process.
     *
     * @param list<string>          $arguments
     * @param array<string, scalar> $iniSettings
     */
    public function console(
        string $alias,
        array $arguments,
        bool $persistent = false,
        bool $handlesOwnShutdown = false,
        array $iniSettings = [],
        int $spawnTimeout = 30000,
    ): ProcessHandle {
        return $this->php(
            alias: $alias,
            cmd: [self::CLI, ...array_values($arguments)],
            persistent: $persistent,
            handlesOwnShutdown: $handlesOwnShutdown,
            iniSettings: $iniSettings,
            spawnTimeout: $spawnTimeout,
        );
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     */
    public function node(
        string $alias,
        array $cmd,
        ?string $cwd = null,
        array $env = [],
        bool $persistent = false,
        bool $handlesOwnShutdown = false,
        int $spawnTimeout = 30000,
    ): ProcessHandle {
        // startProcess destructures spawnTimeout and stopProcess reads
        // handlesOwnShutdown for every start flavour, so omitting them here meant a
        // node child could never be given a longer spawn window and was always
        // tree-killed rather than sent a SIGTERM of its own.
        return $this->post('child-process/start-node', $alias, [
            'alias' => $alias,
            'cmd' => array_values($cmd),
            'cwd' => $cwd,
            'env' => $env,
            'persistent' => $persistent,
            'handlesOwnShutdown' => $handlesOwnShutdown,
            'spawnTimeout' => $spawnTimeout,
        ]);
    }

    /**
     * Stop a process. The runtime clears `persistent` first so the watchdog does
     * not restart it, then either SIGTERMs just this pid (when started with
     * handlesOwnShutdown, on non-Windows) or kills the whole tree.
     */
    public function stop(string $alias): void
    {
        $this->client->post('child-process/stop', ['alias' => $alias]);
    }

    /**
     * Restart a process.
     *
     * Caveat from the runtime: it captures the settings *before* stopping, and
     * `{...undefined}` yields `{}` in JavaScript — so restarting an unknown alias
     * does not 410 as the code intends, it starts a process with empty settings.
     * Check the returned handle rather than assuming a failure surfaced.
     */
    public function restart(string $alias): ProcessHandle
    {
        return $this->post('child-process/restart', $alias, ['alias' => $alias]);
    }

    public function get(string $alias): ?ProcessHandle
    {
        $response = $this->client->get("child-process/get/{$alias}");

        if (410 === $response->status || null === $response->data) {
            return null;
        }

        return ProcessHandle::fromRuntime($alias, (array) $response->data);
    }

    /** @return array<string, ProcessHandle> */
    public function all(): array
    {
        $processes = $this->client->get('child-process')->array();
        $handles = [];

        foreach ($processes as $alias => $data) {
            if (\is_array($data)) {
                $handles[(string) $alias] = ProcessHandle::fromRuntime((string) $alias, $data);
            }
        }

        return $handles;
    }

    /**
     * Send a message to a process over the utilityProcess IPC channel.
     *
     * Answers 200 even when the alias is unknown, so this cannot confirm delivery.
     */
    public function message(string $alias, mixed $message): void
    {
        $this->client->post('child-process/message', ['alias' => $alias, 'message' => $message]);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $endpoint, string $alias, array $payload): ProcessHandle
    {
        $response = $this->client->post($endpoint, array_filter(
            $payload,
            static fn (mixed $v): bool => null !== $v,
        ));

        return ProcessHandle::fromRuntime($alias, (array) ($response->data ?? []));
    }
}
