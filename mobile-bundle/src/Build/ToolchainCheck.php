<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * One thing a mobile build needs, and whether it is here.
 *
 * `hint` is mandatory on purpose. A mobile toolchain failure is the single most likely
 * thing a user of this bundle hits first, and "adb not found" without "install
 * platform-tools, or set ANDROID_HOME" is a dead end for anyone who has not done Android
 * development before.
 */
final class ToolchainCheck
{
    /**
     * @param string      $name        Short label, e.g. `adb`
     * @param string|null $satisfiedBy Where it was found — an absolute path, a directory, or a
     *                                 descriptive value such as an OS name. Null means missing.
     * @param string      $hint        How to get it, shown only when missing
     * @param bool        $required    A false here means the build can proceed degraded
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $satisfiedBy,
        public readonly string $hint,
        public readonly bool $required = true,
    ) {
    }

    public function isSatisfied(): bool
    {
        return null !== $this->satisfiedBy;
    }

    /** A single line for console output: state, name, and either the location or the remedy. */
    public function line(): string
    {
        if ($this->isSatisfied()) {
            return sprintf(' ✓ %s — %s', $this->name, $this->satisfiedBy);
        }

        return sprintf(' %s %s — MISSING. %s', $this->required ? '✗' : '!', $this->name, $this->hint);
    }
}
