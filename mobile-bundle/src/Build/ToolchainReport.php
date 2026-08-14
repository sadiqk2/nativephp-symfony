<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * The result of looking for a platform's build tools.
 *
 * A value rather than console output, so the same detection can be printed by
 * `native:mobile:run`, gate `native:mobile:build`, and be asserted in a test — which is
 * the only kind of verification available for any of this here.
 */
final class ToolchainReport
{
    /** @param list<ToolchainCheck> $checks */
    public function __construct(
        public readonly MobilePlatform $platform,
        public readonly array $checks,
    ) {
    }

    public function isSatisfied(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->required && !$check->isSatisfied()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ToolchainCheck> Everything missing, required or not */
    public function missing(): array
    {
        return array_values(array_filter($this->checks, static fn (ToolchainCheck $c): bool => !$c->isSatisfied()));
    }

    public function get(string $name): ?ToolchainCheck
    {
        foreach ($this->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        return null;
    }

    /** Where a tool was found, for substituting into a planned command. */
    public function pathFor(string $name): ?string
    {
        return $this->get($name)?->satisfiedBy;
    }

    /** @return list<string> One line per check, in declaration order */
    public function lines(): array
    {
        return array_map(static fn (ToolchainCheck $c): string => $c->line(), $this->checks);
    }
}
