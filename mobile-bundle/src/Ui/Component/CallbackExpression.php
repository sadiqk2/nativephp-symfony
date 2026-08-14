<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

/**
 * A parsed callback expression: `'increment'`, or `"delete(3)"`, or `"tag('a', 2)"`.
 *
 * Expressions are what the author writes in `->onPress(...)`; the registry hashes
 * them to the integer ids that cross to the native side (NATIVE-UI-CONTRACT.md §5).
 * On the way back only the id crosses, so this parser never sees device input — but
 * it is still the thing that decides which method name and which literal arguments a
 * dispatch will use, so it is written to refuse rather than to cope.
 *
 * The grammar is deliberately tiny:
 *
 *     expression := method | method '(' args? ')'
 *     method     := [A-Za-z_][A-Za-z0-9_]*
 *     args       := literal (',' literal)*
 *     literal    := int | float | 'string' | "string" | true | false | null
 *
 * Nested arrays and objects are **out of scope**: an argument is a literal written in
 * PHP source next to the element, and anything structured belongs in component state
 * where it needs no round trip. Supporting them would mean shipping a JSON decoder
 * on this path for no use case.
 *
 * Note what is *not* done here: upstream parses arguments by swapping every `'` for
 * `"` and calling `json_decode`. That mangles apostrophes inside strings
 * (`delete('it\'s')`) and silently yields `null` args on any decode failure — a
 * handler called with the wrong values and no error anywhere. This scanner reads the
 * quotes properly and throws instead.
 */
final class CallbackExpression
{
    /**
     * @param list<scalar|null> $arguments Literals written by the author, in order
     */
    private function __construct(
        public readonly string $method,
        public readonly array $arguments,
        public readonly string $raw,
    ) {
    }

    /**
     * @throws CallbackRefused when the expression is not in the grammar above
     */
    public static function parse(string $expression): self
    {
        $parenthesis = strpos($expression, '(');

        if (false === $parenthesis) {
            self::assertMethodName($expression, $expression);

            return new self($expression, [], $expression);
        }

        if (!str_ends_with($expression, ')')) {
            throw new CallbackRefused(sprintf('Callback expression "%s" opens an argument list but does not close it.', $expression));
        }

        $method = substr($expression, 0, $parenthesis);
        self::assertMethodName($method, $expression);

        $arguments = substr($expression, $parenthesis + 1, -1);

        return new self($method, self::parseArguments($arguments, $expression), $expression);
    }

    /**
     * Framework-reserved expressions use a `__` prefix — currently only
     * `__navigate('<key>')`, which `Element::toArray()` registers for a navigating
     * press. They are handled by the component base rather than by an app action,
     * and no application method may carry that prefix.
     */
    public function isReserved(): bool
    {
        return str_starts_with($this->method, '__');
    }

    private static function assertMethodName(string $method, string $expression): void
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
            throw new CallbackRefused(sprintf('Callback expression "%s" does not name a method.', $expression));
        }
    }

    /**
     * @return list<scalar|null>
     *
     * @throws CallbackRefused
     */
    private static function parseArguments(string $arguments, string $expression): array
    {
        $tokens = self::tokenise($arguments, $expression);

        if (1 === \count($tokens) && '' === trim($tokens[0])) {
            return [];
        }

        return array_map(
            static fn (string $token): string|int|float|bool|null => self::literal(trim($token), $expression),
            $tokens,
        );
    }

    /**
     * Split on commas that are not inside a quoted string.
     *
     * @return list<string>
     */
    private static function tokenise(string $arguments, string $expression): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $length = \strlen($arguments);

        for ($i = 0; $i < $length; ++$i) {
            $character = $arguments[$i];

            if (null !== $quote) {
                // Keep the escape *and* the escaped character; unescaping happens
                // once, in literal(), so the scanner cannot lose a quote here.
                if ('\\' === $character && $i + 1 < $length) {
                    $current .= $character.$arguments[++$i];

                    continue;
                }

                $current .= $character;

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ("'" === $character || '"' === $character) {
                $quote = $character;
                $current .= $character;

                continue;
            }

            if (',' === $character) {
                $tokens[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        if (null !== $quote) {
            throw new CallbackRefused(sprintf('Callback expression "%s" has an unterminated string argument.', $expression));
        }

        $tokens[] = $current;

        return $tokens;
    }

    /** @throws CallbackRefused */
    private static function literal(string $token, string $expression): string|int|float|bool|null
    {
        if ('' === $token) {
            throw new CallbackRefused(sprintf('Callback expression "%s" has an empty argument.', $expression));
        }

        $quote = $token[0];

        if ("'" === $quote || '"' === $quote) {
            if (\strlen($token) < 2 || !str_ends_with($token, $quote)) {
                throw new CallbackRefused(sprintf('Callback expression "%s" has an unterminated string argument.', $expression));
            }

            return self::unescape(substr($token, 1, -1), $quote, $expression);
        }

        return match ($token) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => self::number($token, $expression),
        };
    }

    /**
     * Only `\\`, `\'` and `\"` are escapes. An unrecognised escape is refused rather
     * than passed through, because `'C:\next'` silently meaning something other than
     * what it looks like is exactly the class of bug this whole file exists to avoid.
     *
     * @throws CallbackRefused
     */
    private static function unescape(string $body, string $quote, string $expression): string
    {
        $result = '';
        $length = \strlen($body);

        for ($i = 0; $i < $length; ++$i) {
            $character = $body[$i];

            if ('\\' !== $character) {
                // An unescaped quote inside the body means the token was not one
                // string but something spliced, e.g. `'a'.'b'`.
                if ($character === $quote) {
                    throw new CallbackRefused(sprintf('Callback expression "%s" has a malformed string argument.', $expression));
                }

                $result .= $character;

                continue;
            }

            $next = $body[$i + 1] ?? '';

            if ('\\' !== $next && "'" !== $next && '"' !== $next) {
                throw new CallbackRefused(sprintf('Callback expression "%s" uses an unsupported escape "\\%s".', $expression, $next));
            }

            $result .= $next;
            ++$i;
        }

        return $result;
    }

    /** @throws CallbackRefused */
    private static function number(string $token, string $expression): int|float
    {
        if (1 === preg_match('/^-?(0|[1-9][0-9]*)$/', $token)) {
            return (int) $token;
        }

        if (1 === preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]+$/', $token)) {
            return (float) $token;
        }

        throw new CallbackRefused(sprintf('Callback expression "%s" has an argument "%s" that is not a literal.', $expression, $token));
    }
}
