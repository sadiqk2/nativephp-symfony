<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\EventBridge\EventFactory;
use PHPUnit\Framework\TestCase;

/**
 * The runtime's event payloads are positional lists, so arity and order are the contract.
 *
 * `ContractCoverageTest` checks that every event upstream can send has a class here.
 * `PayloadKeyContractTest` checks the keys we send *to* the runtime. This checks the shape of
 * what it sends *back*: `notifyLaravel('events', { event, payload: [...] })` reaches
 * `EventFactory::create()`, which spreads the payload into a constructor. A missing value
 * degrades the event to a generic `NativeEvent` — visible, if annoying. A value in the wrong
 * position does not: `WindowResized` would carry a height in `$width` and nothing anywhere
 * would say so.
 *
 * The two events with more than one positional value are asserted by name below, because
 * arity alone cannot catch a swap and there are few enough to state exactly.
 *
 * Every `notifyLaravel('events', { … })` site in the runtime is accounted for, and the
 * counts below are exact rather than floors — a parser that quietly stops recognising a
 * form has to fail here instead of comparing fewer sites and still reporting OK.
 *
 * Skips when the runtime sources are not checked out, as the other contract tests do.
 */
final class EventPayloadShapeTest extends TestCase
{
    /**
     * The runtime's event sites, counted. Every `notifyLaravel('events', …)` object falls
     * into exactly one of these four buckets, and they sum to EVENT_SITES — so a form the
     * parser stops recognising cannot slip out of the comparison unnoticed.
     */
    private const int EVENT_SITES = 48;
    private const int POSITIONAL_SITES = 13;
    private const int NAMED_SITES = 25;
    private const int PAYLOADLESS_SITES = 9;
    /** `globalShortcut` sends whatever event class the request registered, so there is no name to compare. */
    private const int DYNAMIC_NAME_SITES = 1;

    /**
     * Object payloads, collected while parsing.
     *
     * A list per event name, not one payload: three sites send `StartupError` and two send
     * `PowerStateChanged`, and keying by name alone let the last site overwrite the others —
     * a wrong key at any but the last of them was compared against nothing.
     *
     * @var array<string, list<list<string>>>
     */
    private array $named = [];

    /** @var array{sites: int, payloadless: int, dynamic: int} */
    private array $counts = ['sites' => 0, 'payloadless' => 0, 'dynamic' => 0];

    /**
     * The factory's event map, read by reflection.
     *
     * Private on purpose — it is the wire contract's internal table, not API — and widening
     * it so a test can look would be the test dictating the design.
     *
     * @return array<string, class-string>
     */
    private function eventMap(): array
    {
        /** @var array<string, class-string> $map */
        $map = (new \ReflectionClass(EventFactory::class))->getConstant('MAP');

        return $map;
    }

    public function testEveryPositionalPayloadMatchesItsConstructorArity(): void
    {
        $sent = $this->payloadsTheRuntimeSends();
        $map = $this->eventMap();

        if ([] === $sent) {
            self::markTestSkipped('Runtime sources not available.');
        }

        $checked = 0;
        $problems = [];

        foreach ($sent as $event => $variants) {
            $class = $map[$event] ?? null;

            if (null === $class || !class_exists($class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            $parameters = null === $constructor ? [] : $constructor->getParameters();
            $required = \count(array_filter($parameters, static fn (\ReflectionParameter $p): bool => !$p->isOptional()));

            foreach ($variants as $values) {
                ++$checked;

                if (\count($values) < $required || \count($values) > \count($parameters)) {
                    $problems[] = sprintf(
                        '%s: the runtime sends %d value(s), %s takes %d (%d required)',
                        $event,
                        \count($values),
                        $class,
                        \count($parameters),
                        $required,
                    );
                }
            }
        }

        // Exact, not a floor. Every site sending a literal `payload: [...]` is in the
        // factory's map, so the count is the surface, and a floor here would let a parser
        // change compare fewer sites and still report OK.
        self::assertSame(self::POSITIONAL_SITES, $checked, 'Fewer positional payloads were compared than the runtime sends, so this test has stopped covering them.');
        self::assertSame([], $problems, "A payload the constructor cannot take degrades the event to NativeEvent:\n".implode("\n", $problems));
    }

    public function testEveryNamedPayloadKeyIsAConstructorParameter(): void
    {
        // Twelve events send `payload: { … }`, which PHP spreads as named arguments — so
        // here the *names* are the contract and a rename on either side breaks the class.
        // It fails loudly rather than silently (the factory degrades to NativeEvent and
        // records the error), but "loudly" means in a log on a device nobody is watching.
        $this->payloadsTheRuntimeSends();
        $map = $this->eventMap();

        if ([] === $this->named) {
            self::markTestSkipped('Runtime sources not available.');
        }

        $checked = 0;
        $problems = [];

        foreach ($this->named as $event => $variants) {
            $class = $map[$event] ?? null;

            if (null === $class || !class_exists($class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            $parameters = null === $constructor ? [] : $constructor->getParameters();
            $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters);

            foreach ($variants as $keys) {
                ++$checked;

                $unknown = array_values(array_diff($keys, $names));
                $unfilled = array_values(array_diff(
                    array_map(
                        static fn (\ReflectionParameter $p): string => $p->getName(),
                        array_filter($parameters, static fn (\ReflectionParameter $p): bool => !$p->isOptional()),
                    ),
                    $keys,
                ));

                if ([] !== $unknown || [] !== $unfilled) {
                    $problems[] = sprintf(
                        '%s sends [%s]; %s takes [%s]%s',
                        $event,
                        implode(', ', $keys),
                        $class,
                        implode(', ', $names),
                        [] === $unfilled ? '' : sprintf(' — required and unfilled: %s', implode(', ', $unfilled)),
                    );
                }
            }
        }

        // Sites, not names: `StartupError` is sent from three places and `PowerStateChanged`
        // from two, and counting names would let two of the three go uncompared.
        self::assertSame(self::NAMED_SITES, $checked, 'Fewer named payloads were compared than the runtime sends, so this test has stopped covering them.');
        self::assertSame([], $problems, "A named payload that does not fit the constructor degrades the event:\n".implode("\n", $problems));
    }

    public function testTheParserAccountsForEveryEventSiteInTheRuntime(): void
    {
        $sent = $this->payloadsTheRuntimeSends();

        if ([] === $sent) {
            self::markTestSkipped('Runtime sources not available.');
        }

        // The point of the sum: a site the parser stops recognising has to land somewhere,
        // and every bucket is pinned, so it cannot quietly leave the comparison. Bump these
        // only after reading the new sites — that is the decision this test forces.
        self::assertSame(self::EVENT_SITES, $this->counts['sites'], 'The runtime has a different number of event sites than this test accounts for.');
        self::assertSame(self::PAYLOADLESS_SITES, $this->counts['payloadless'], 'A site gained or lost its payload.');
        self::assertSame(self::DYNAMIC_NAME_SITES, $this->counts['dynamic'], 'A site names its event class dynamically, so nothing here can compare it.');
        self::assertSame(
            self::EVENT_SITES,
            self::POSITIONAL_SITES + self::NAMED_SITES + self::PAYLOADLESS_SITES + self::DYNAMIC_NAME_SITES,
            'The four buckets no longer add up to the number of sites, so some are counted twice or not at all.',
        );
    }

    public function testTheTwoEventsWithSeveralValuesAreInTheRightOrder(): void
    {
        $sent = $this->payloadsTheRuntimeSends();
        $map = $this->eventMap();

        if ([] === $sent) {
            self::markTestSkipped('Runtime sources not available.');
        }

        // Electron's getSize() returns [width, height], and the runtime spreads it in that
        // order after the window id. A swap here is invisible: both are ints, both are
        // plausible, and nothing downstream can tell. One entry each, so this also pins
        // that neither event grew a second site sending something else.
        self::assertSame(
            [['id', 'window.getSize()[0]', 'window.getSize()[1]']],
            $sent['Native\\Desktop\\Events\\Windows\\WindowResized'] ?? [],
            'WindowResized: if upstream reorders this, $width and $height silently swap.',
        );

        self::assertSame(
            [['alias', 'proc.pid']],
            $sent['Native\\Desktop\\Events\\ChildProcess\\ProcessSpawned'] ?? [],
            'ProcessSpawned: alias first, pid second.',
        );

        // And the constructors that receive them, in the same order.
        self::assertSame(['id', 'width', 'height'], $this->parameterNames($map['Native\\Desktop\\Events\\Windows\\WindowResized']));
        self::assertSame(['alias', 'pid'], $this->parameterNames($map['Native\\Desktop\\Events\\ChildProcess\\ProcessSpawned']));
    }

    /** @return list<string> */
    private function parameterNames(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        return array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            null === $constructor ? [] : $constructor->getParameters(),
        );
    }

    /**
     * Event class → the positional payloads the runtime sends, as source expressions.
     *
     * One entry per *site*, not per event name. Only `payload: [...]` literals: a site
     * building its payload elsewhere is counted but not guessed at.
     *
     * @return array<string, list<list<string>>>
     */
    private function payloadsTheRuntimeSends(): array
    {
        $root = __DIR__.'/../../upstream/np-desktop/resources/electron';

        if (!is_dir($root)) {
            return [];
        }

        $sent = [];
        // Reset, because both collections now append per site: parsing twice in one test
        // would otherwise double every count.
        $this->named = [];
        $this->counts = ['sites' => 0, 'payloadless' => 0, 'dynamic' => 0];

        foreach ($this->sources($root) as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match_all("/notifyLaravel\\(\\s*'events'\\s*,\\s*\\{/", $source, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$match, $offset]) {
                // childProcess.ts keeps a commented-out `error` listener as a note to
                // itself. It is not a site, and counting it made StartupError look as
                // though it sent a payload of comment markers.
                if ($this->isCommentedOut($source, $offset)) {
                    continue;
                }

                $object = $this->balanced($source, strpos($source, '{', $offset) ?: $offset, '{', '}');
                ++$this->counts['sites'];

                // The event class is written three ways: a single-quoted literal, a
                // backtick-quoted one (every AutoUpdater and PowerMonitor site), and a
                // fallback `menuItem.event || '…'`. Matching only the first left eighteen of the
                // forty-eight sites out of the comparison while this test still reported OK.
                if (!preg_match('/event:\\s*(?:[^\'`,\\n]*\\|\\|\\s*)?[\'`]([^\'`]+)[\'`]/', $object, $event)) {
                    ++$this->counts['dynamic'];

                    continue;
                }

                if (!preg_match('/payload:\\s*/', $object, $payloadAt, \PREG_OFFSET_CAPTURE)) {
                    ++$this->counts['payloadless'];

                    continue;
                }

                $after = ltrim(substr($object, $payloadAt[0][1] + \strlen($payloadAt[0][0])));

                $name = ltrim(str_replace('\\\\', '\\', $event[1]), '\\');

                if (str_starts_with($after, '[')) {
                    $sent[$name][] = $this->topLevelItems($this->balanced($after, 0, '[', ']'));

                    continue;
                }

                // An object payload spreads as *named* arguments, so the keys are the
                // contract rather than the order. Kept separately: comparing a name against
                // a position would be nonsense.
                if (str_starts_with($after, '{')) {
                    $keys = [];

                    foreach ($this->topLevelItems($this->balanced($after, 0, '{', '}')) as $entry) {
                        $keys[] = trim(explode(':', $entry, 2)[0]);
                    }

                    $this->named[$name][] = array_values(array_filter($keys));
                }
            }
        }

        return $sent;
    }

    /** @return list<string> */
    private function sources(string $root): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo
                && \in_array($file->getExtension(), ['ts', 'mts'], true)
                && !str_contains($file->getPathname(), 'node_modules')
            ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** Whether the match at `$offset` sits behind a `//` on its own line. */
    private function isCommentedOut(string $source, int $offset): bool
    {
        $before = substr($source, 0, $offset);
        $lineStart = strrpos($before, "\n");

        return str_contains(false === $lineStart ? $before : substr($before, $lineStart), '//');
    }

    private function balanced(string $source, int $start, string $open, string $close): string
    {
        $depth = 0;

        for ($i = $start, $length = \strlen($source); $i < $length; ++$i) {
            if ($open === $source[$i]) {
                ++$depth;
            } elseif ($close === $source[$i]) {
                if (0 === --$depth) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    /**
     * Split a bracketed list on its top-level commas — `getSize()[0]` is one item, not two.
     *
     * @return list<string>
     */
    private function topLevelItems(string $list): array
    {
        $body = trim(substr($list, 1, -1));

        if ('' === $body) {
            return [];
        }

        $items = [];
        $current = '';
        $depth = 0;

        for ($i = 0, $length = \strlen($body); $i < $length; ++$i) {
            $char = $body[$i];

            if (str_contains('([{', $char)) {
                ++$depth;
            }

            if (str_contains(')]}', $char)) {
                --$depth;
            }

            if (',' === $char && 0 === $depth) {
                $items[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if ('' !== trim($current)) {
            $items[] = trim($current);
        }

        return $items;
    }
}
