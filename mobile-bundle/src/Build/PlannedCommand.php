<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * One external command a build or run would execute.
 *
 * Exists so the *decision* about what to run is separable from running it. Nothing in
 * this environment can execute Gradle or xcodebuild — there is no Android SDK and no
 * Xcode — so the only honest way to ship `native:mobile:run` and `native:mobile:build`
 * is to make the plan a value, test the plan, and print it verbatim under `--dry-run`.
 * What the commands do once the toolchain answers is unverified here, and every place
 * that matters says so.
 *
 * `argv` is a list, never a shell string: an app id or a device serial reaching a shell
 * unquoted is both a quoting bug and an injection, and upstream builds several of these
 * by string interpolation.
 */
final class PlannedCommand
{
    /**
     * @param list<string> $argv    The program and its arguments, unescaped
     * @param string       $cwd     Working directory; Gradle in particular only works from the project root
     * @param string       $purpose Human-readable, shown in dry-run output
     * @param int|null     $timeout Seconds, or null for "as long as it takes" — a cold Gradle
     *                              daemon or a first Xcode build legitimately runs for many minutes
     * @param bool         $tolerateFailure A non-zero exit does not stop the sequence. Needed
     *                                      because some steps are advisory: `simctl boot` fails
     *                                      when the simulator is already booted, which is the
     *                                      state we wanted anyway.
     */
    public function __construct(
        public readonly array $argv,
        public readonly string $cwd,
        public readonly string $purpose,
        public readonly ?int $timeout = null,
        public readonly bool $tolerateFailure = false,
    ) {
        if ([] === $argv) {
            throw new \InvalidArgumentException('A planned command needs at least a program name.');
        }
    }

    public function program(): string
    {
        return $this->argv[0];
    }

    /**
     * A copy-pasteable rendering, for dry-run output and logs.
     *
     * Shell-quoted so that pasting it into a terminal runs the same thing the runner
     * would have run — it is never used to *build* what gets executed.
     */
    public function display(): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $arg) ? $arg : escapeshellarg($arg),
            $this->argv,
        ));
    }

    /** With the program replaced by an absolute path, once the toolchain has been located. */
    public function withProgram(string $absolutePath): self
    {
        return new self(
            [$absolutePath, ...\array_slice($this->argv, 1)],
            $this->cwd,
            $this->purpose,
            $this->timeout,
            $this->tolerateFailure,
        );
    }
}
