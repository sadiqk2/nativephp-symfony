<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * Executes a {@see PlannedCommand}.
 *
 * An interface for one reason: the real implementation cannot be exercised against the
 * mobile toolchains here, so the commands are tested against a recording double and the
 * process plumbing is tested separately against harmless programs. Anything that mixed
 * the two would be untestable in this environment and therefore unverifiable.
 */
interface CommandRunnerInterface
{
    /**
     * @param (callable(string $type, string $chunk): void)|null $onOutput Receives 'out'/'err' chunks as they arrive
     *
     * @return int The exit code; 127 conventionally means the program was not found
     */
    public function run(PlannedCommand $command, ?callable $onOutput = null): int;
}
