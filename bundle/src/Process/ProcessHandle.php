<?php

declare(strict_types=1);

namespace Native\Symfony\Process;

/**
 * A runtime-managed child process.
 *
 * `pid` is null on the synchronous response to a start call — the runtime returns
 * before Electron's `spawn` event fires. Read it from ProcessSpawned, or re-read
 * the handle with ChildProcessManager::get().
 */
final class ProcessHandle
{
    /**
     * @param list<string>         $cmd
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly string $alias,
        public readonly ?int $pid,
        public readonly array $cmd = [],
        public readonly array $settings = [],
        /**
         * The runtime's own message when the fork failed.
         *
         * startProcess catches a failed spawn and answers 200 with
         * `{pid: null, proc: null, settings, error}`. Since the success path also
         * returns a null pid — the runtime replies before Electron's spawn event —
         * this string is the only thing separating "starting" from "never started".
         */
        public readonly ?string $error = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromRuntime(string $alias, array $data): self
    {
        /** @var array<string, mixed> $settings */
        $settings = \is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $cmd = \is_array($settings['cmd'] ?? null) ? array_map('strval', $settings['cmd']) : [];

        return new self(
            alias: $alias,
            pid: isset($data['pid']) && is_numeric($data['pid']) ? (int) $data['pid'] : null,
            cmd: array_values($cmd),
            settings: $settings,
            error: \is_string($data['error'] ?? null) && '' !== $data['error'] ? $data['error'] : null,
        );
    }

    public function isRunning(): bool
    {
        return null !== $this->pid;
    }

    /** Whether the runtime reported that this process could not be started. */
    public function failed(): bool
    {
        return null !== $this->error;
    }

    public function isPersistent(): bool
    {
        return (bool) ($this->settings['persistent'] ?? false);
    }
}
