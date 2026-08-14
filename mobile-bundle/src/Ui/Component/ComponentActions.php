<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

/**
 * Resolves a callback expression to a method that is permitted to run.
 *
 * The security boundary lives here, and it is worth being explicit about what the
 * threat actually is. The native side sends back an integer. It cannot send an
 * expression, so it cannot name a method — the worst a compromised or confused native
 * layer can do is send an id the app registered, which is indistinguishable from a
 * user tapping that node. What *this* class defends against is the other half: the
 * expression side is a string, authored in PHP but assembled from loop variables and
 * template data, and a string that reaches `$component->$method(...$args)` unchecked
 * is one interpolation away from being an arbitrary call.
 *
 * So three rules, all default-deny:
 *
 *  1. The method must carry `#[NativeAction]`. Nothing else is reachable — not a
 *     public helper, not an inherited framework method, not a magic method.
 *  2. The match is exact and case-sensitive, even though PHP method names are not.
 *     `->onPress('Increment')` is refused rather than quietly folded onto
 *     `increment()`, which keeps the mapping between callback ids and actions 1:1 —
 *     two spellings of one action would otherwise be two ids for one handler and
 *     defeat the id stability that subtree reuse depends on.
 *  3. Arguments must fit the signature, and device payload is never appended to a
 *     variadic (see {@see eventArgumentSlots}).
 *
 * Reflection results are cached per class because this runs on every dispatch, and a
 * dispatch is on the interaction path where a phone is waiting for a repaint.
 */
final class ComponentActions
{
    /** @var array<class-string, array<string, \ReflectionMethod>> */
    private static array $cache = [];

    /**
     * The permitted actions for a component class, keyed by method name.
     *
     * @param class-string<NativeComponent> $class
     *
     * @return array<string, \ReflectionMethod>
     */
    public static function forClass(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $actions = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract()) {
                continue;
            }

            // Defence in depth: a `#[NativeAction]` on `__invoke` or `__call` would
            // otherwise be dispatchable, and `__`-prefixed expressions are reserved
            // for the framework's own callbacks (`__navigate`).
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            if ([] === $method->getAttributes(NativeAction::class)) {
                continue;
            }

            $actions[$method->getName()] = $method;
        }

        return self::$cache[$class] = $actions;
    }

    /**
     * Resolve an expression against a component, or refuse.
     *
     * @throws CallbackRefused
     */
    public static function resolve(NativeComponent $component, CallbackExpression $expression): \ReflectionMethod
    {
        $actions = self::forClass($component::class);
        $method = $actions[$expression->method] ?? null;

        if (null === $method) {
            throw new CallbackRefused(sprintf(
                'Refused callback "%s": %s::%s() is not a #[NativeAction]. Permitted actions: %s.',
                $expression->raw,
                $component::class,
                $expression->method,
                [] === $actions ? '(none)' : implode(', ', array_keys($actions)),
            ));
        }

        if (\count($expression->arguments) > self::literalArgumentCapacity($method)) {
            throw new CallbackRefused(sprintf(
                'Refused callback "%s": %s::%s() cannot take %d argument(s).',
                $expression->raw,
                $component::class,
                $expression->method,
                \count($expression->arguments),
            ));
        }

        return $method;
    }

    /**
     * How many of an event's payload values may be appended to a call.
     *
     * Two guards in one number. The obvious one is arity — passing more than the
     * signature accepts is legal in PHP for a userland method and would silently do
     * nothing, so the surplus is dropped here where it can be reasoned about.
     *
     * The less obvious one: **device payload never enters a variadic**. A handler
     * declared `save(string ...$fields)` states nothing about how many values it
     * expects, so appending device-controlled ones means the number of arguments an
     * attacker (or a renderer bug) controls is unbounded — and a handler that
     * iterates `$fields` would act on values the author never wrote. Literal
     * arguments from the expression may fill a variadic; the event may not.
     */
    public static function eventArgumentSlots(\ReflectionMethod $method, int $literalCount): int
    {
        $parameters = $method->getParameters();
        $next = $parameters[$literalCount] ?? null;

        if (null === $next || $next->isVariadic()) {
            return 0;
        }

        return \count($parameters) - $literalCount;
    }

    /**
     * @throws CallbackRefused when the assembled call would not satisfy the signature
     *
     * @param list<mixed> $arguments
     */
    public static function assertSatisfies(\ReflectionMethod $method, array $arguments, string $expression): void
    {
        if (\count($arguments) < $method->getNumberOfRequiredParameters()) {
            throw new CallbackRefused(sprintf(
                'Refused callback "%s": %s::%s() requires %d argument(s), %d available.',
                $expression,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $method->getNumberOfRequiredParameters(),
                \count($arguments),
            ));
        }
    }

    /** Literal arguments may fill a variadic, so an unbounded signature has no cap. */
    private static function literalArgumentCapacity(\ReflectionMethod $method): int
    {
        return $method->isVariadic() ? \PHP_INT_MAX : $method->getNumberOfParameters();
    }
}
