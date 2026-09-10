<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * Runs commands with `proc_open`, streaming their output as it arrives.
 *
 * Deliberately not symfony/process: this bundle does not require it (the mobile runtime
 * spawns nothing of its own), and adding a dependency so a build command can shell out
 * three times is a poor trade. The cost is this class — about the smallest useful subset
 * of Process: argv arrays, no shell, live output, a timeout.
 *
 * **Verification status.** The plumbing here is exercised by the test suite against
 * ordinary programs (`/bin/sh`, a nonexistent binary, a slow loop for the timeout). It
 * has *never* been used to drive Gradle, adb, xcodebuild or simctl, because none of them
 * exist in this environment. Long-running interactive toolchains can behave differently
 * from a test process — notably, Gradle and xcodebuild detect a TTY and change their
 * output format, and this runner never allocates one, so their output will be the
 * non-interactive variant.
 */
final class ProcessRunner implements CommandRunnerInterface
{
    /**
     * @param float $pollInterval Seconds to sleep when neither pipe has data, to avoid a busy loop
     */
    public function __construct(private readonly float $pollInterval = 0.05)
    {
    }

    public function run(PlannedCommand $command, ?callable $onOutput = null): int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];

        // Suppressed: proc_open emits a warning for a missing program on some platforms
        // and returns false on others. Both mean the same thing to a caller, and the
        // toolchain report is where a missing binary should have been explained already.
        $process = @proc_open($command->argv, $descriptors, $pipes, $command->cwd);

        if (!\is_resource($process)) {
            return 127;
        }

        foreach ([1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
        }

        $deadline = null === $command->timeout ? null : microtime(true) + $command->timeout;
        $open = [1 => 'out', 2 => 'err'];

        while ([] !== $open) {
            $read = array_map(static fn (int $fd): mixed => $pipes[$fd], array_keys($open));
            $write = $except = [];

            // A short select timeout rather than a blocking one, so the deadline below is
            // still checked while a silent process runs.
            @stream_select($read, $write, $except, 0, (int) ($this->pollInterval * 1_000_000));

            foreach ($open as $fd => $type) {
                $chunk = fread($pipes[$fd], 8192);

                if (\is_string($chunk) && '' !== $chunk) {
                    if (null !== $onOutput) {
                        $onOutput($type, $chunk);
                    }

                    continue;
                }

                // `false` is not "no data yet" — a healthy non-blocking pipe with nothing
                // to read returns '', verified against a real proc_open pipe. `false` is
                // reserved for the stream itself failing to be read, and feof() is not
                // guaranteed to follow: without this, an fd that starts erroring never
                // leaves $open, and with $command->timeout null — the default, and what a
                // cold Gradle build actually runs with — that is this method hanging
                // forever rather than returning an exit code.
                if (feof($pipes[$fd]) || false === $chunk) {
                    fclose($pipes[$fd]);
                    unset($open[$fd]);
                }
            }

            if (null !== $deadline && microtime(true) > $deadline) {
                foreach ($open as $fd => $_) {
                    fclose($pipes[$fd]);
                }

                // 9, not SIGKILL: the constant lives in ext-pcntl, which is not required
                // here and is absent from plenty of PHP builds. SIGTERM would be politer,
                // but a Gradle daemon that has stopped answering will not honour it either.
                proc_terminate($process, 9);
                proc_close($process);

                throw new \RuntimeException(sprintf(
                    'Command "%s" exceeded its %d second timeout and was killed.',
                    $command->display(),
                    $command->timeout,
                ));
            }
        }

        return proc_close($process);
    }
}
