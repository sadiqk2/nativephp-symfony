<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui;

/**
 * Maps callable expressions to the integer ids that cross into the native layer.
 *
 * Interactions come back carrying an id, not a closure, so a registry has to
 * survive from render to interaction. Ids are derived from a hash of the
 * expression rather than a counter, so the same handler keeps the same id across
 * frames — which is what lets the native side keep its bindings when a subtree
 * is reused.
 *
 * See NATIVE-UI-CONTRACT.md §5.
 */
final class CallbackRegistry
{
    /** @var array<int, string> */
    private array $map = [];

    /** @var array<string, int> */
    private array $expressionMap = [];

    /** @var array<int, string> */
    private array $kindMap = [];

    /** @var array<string, array<string, mixed>> */
    private array $navigationConfigs = [];

    public function __construct(private readonly string $scope = '')
    {
    }

    public function scope(): string
    {
        return $this->scope;
    }

    /**
     * Register an expression and return its id.
     *
     * Collisions are astronomically unlikely (~1 in 2^31) but not impossible, and a
     * collision would silently run the wrong handler — so it rehashes with a salt
     * rather than trusting the odds.
     */
    public function register(string $expression, ?string $kind = null): int
    {
        if (isset($this->expressionMap[$expression])) {
            $id = $this->expressionMap[$expression];

            if (null !== $kind) {
                $this->kindMap[$id] = $kind;
            }

            return $id;
        }

        $id = $this->deriveId($expression);

        $this->map[$id] = $expression;
        $this->expressionMap[$expression] = $id;

        if (null !== $kind) {
            $this->kindMap[$id] = $kind;
        }

        return $id;
    }

    /** @param array<string, mixed> $config */
    public function registerNavigation(array $config): string
    {
        $key = 'n'.substr(md5((string) json_encode($config)), 0, 8);

        $this->navigationConfigs[$key] = $config;

        return $key;
    }

    public function expression(int $id): ?string
    {
        return $this->map[$id] ?? null;
    }

    public function kind(int $id): ?string
    {
        return $this->kindMap[$id] ?? null;
    }

    public function idFor(string $expression): ?int
    {
        return $this->expressionMap[$expression] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function navigation(string $key): ?array
    {
        return $this->navigationConfigs[$key] ?? null;
    }

    /** @return array<string, int> */
    public function expressions(): array
    {
        return $this->expressionMap;
    }

    /**
     * Masked to **31** bits, unlike node ids which use all 32.
     *
     * The Kotlin side reads callback ids as a signed Int, so the sign bit must stay
     * clear or a full-u32 id wraps negative on the round trip and resolution misses
     * silently. 0 is nudged to 1 because the native side uses 0 for "no callback".
     *
     * A scope, when set, is joined with \x1F — a separator that cannot appear in an
     * expression, so two scopes cannot collide by concatenation.
     */
    private function deriveId(string $expression): int
    {
        $input = '' === $this->scope ? $expression : $this->scope."\x1F".$expression;

        $id = Element::fnv1a32($input) & 0x7FFFFFFF;

        if (0 === $id) {
            $id = 1;
        }

        for ($salt = 1; isset($this->map[$id]); ++$salt) {
            $id = Element::fnv1a32($input."\x00".$salt) & 0x7FFFFFFF;

            if (0 === $id) {
                $id = 1;
            }
        }

        return $id;
    }
}
