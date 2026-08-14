<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Elements;
use PHPUnit\Framework\TestCase;

/**
 * Byte-compares this package's element tree against the one upstream's own collector
 * produces for an equivalent template.
 *
 * This is the test that makes NATIVE-UI-CONTRACT.md trustworthy. The renderers on the
 * far side are upstream's Kotlin and Swift, so "our format looks reasonable" is worth
 * nothing — it has to be identical, content hashes included.
 *
 * Upstream's Edge classes are loaded through a stub PSR-4 autoloader: Element and
 * CallbackRegistry turn out to have no framework dependencies at all (their only
 * mentions of Blade are in comments), so they run standalone. Skips when the upstream
 * checkout is absent.
 */
final class UiWireFormatTest extends TestCase
{
    private static bool $upstreamLoaded = false;

    protected function setUp(): void
    {
        if (!self::loadUpstream()) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }
    }

    public function testASimpleTreeMatchesUpstreamExactly(): void
    {
        $nextId1 = 1;
        $mine = Elements\Column::make(
            Elements\Text::make('Hello from Symfony'),
            Elements\Button::make('Tap me'),
        )->toArray(new CallbackRegistry(), $nextId1);

        $nextId2 = 1;
        $theirs = \Native\Mobile\Edge\Elements\Column::make(
            \Native\Mobile\Edge\Elements\Text::make('Hello from Symfony'),
            \Native\Mobile\Edge\Elements\Button::make('Tap me'),
        )->toArray(new \Native\Mobile\Edge\CallbackRegistry(), $nextId2);

        self::assertSame($theirs, $mine, 'The element tree must be byte-identical to upstream, hashes included.');
    }

    public function testContentHashesMatchIncludingNesting(): void
    {
        // The hash folds child hashes in (Merkle), so a nested tree tests the whole
        // input list and its order at once.
        $n1 = 1;
        $mine = Elements\Column::make(
            Elements\Row::make(
                Elements\Text::make('a'),
                Elements\Text::make('b'),
            ),
            Elements\Spacer::make(),
        )->toArray(new CallbackRegistry(), $n1);

        $n2 = 1;
        $theirs = \Native\Mobile\Edge\Elements\Column::make(
            \Native\Mobile\Edge\Elements\Row::make(
                \Native\Mobile\Edge\Elements\Text::make('a'),
                \Native\Mobile\Edge\Elements\Text::make('b'),
            ),
            \Native\Mobile\Edge\Elements\Spacer::make(),
        )->toArray(new \Native\Mobile\Edge\CallbackRegistry(), $n2);

        self::assertSame($theirs['_hash'], $mine['_hash']);
        self::assertSame($theirs, $mine);
    }

    public function testSequentialIdsMatch(): void
    {
        $n1 = 1;
        $mine = Elements\Column::make(
            Elements\Text::make('one'),
            Elements\Text::make('two'),
            Elements\Text::make('three'),
        )->toArray(new CallbackRegistry(), $n1);

        self::assertSame([1, 2, 3, 4], [
            $mine['id'],
            $mine['children'][0]['id'],
            $mine['children'][1]['id'],
            $mine['children'][2]['id'],
        ]);
    }

    public function testEmptyLayoutStyleAndPropsAreOmittedNotSentAsEmptyObjects(): void
    {
        // A frame goes over the bridge on every interaction; empty objects on every
        // node add up.
        $n = 1;
        $node = Elements\Column::make()->toArray(new CallbackRegistry(), $n);

        self::assertSame(['id', 'type', '_hash'], array_keys($node));

        // A Spacer, by contrast, carries its layout default — the emission rule is
        // "omit when empty", not "omit always".
        $n = 1;
        $spacer = Elements\Spacer::make()->toArray(new CallbackRegistry(), $n);

        self::assertSame(['flex_grow' => 1], $spacer['layout']);
    }

    public function testKeyedNodesGetStableIdsAcrossReorders(): void
    {
        // The reason keys exist: an unkeyed list that reorders reuses the wrong nodes,
        // losing scroll position and input focus.
        $n1 = 1;
        $n2 = 1;
        $first = Elements\Column::make(
            Elements\Text::make('a')->key('item-a'),
            Elements\Text::make('b')->key('item-b'),
        )->toArray(new CallbackRegistry(), $n1);

        $reordered = Elements\Column::make(
            Elements\Text::make('b')->key('item-b'),
            Elements\Text::make('a')->key('item-a'),
        )->toArray(new CallbackRegistry(), $n2);

        $idsFirst = [
            'a' => $first['children'][0]['id'],
            'b' => $first['children'][1]['id'],
        ];
        $idsReordered = [
            'b' => $reordered['children'][0]['id'],
            'a' => $reordered['children'][1]['id'],
        ];

        self::assertSame($idsFirst['a'], $idsReordered['a']);
        self::assertSame($idsFirst['b'], $idsReordered['b']);
    }

    public function testAnUnchangedSubtreeIsEmittedAsAReuseMarker(): void
    {
        $registry = new CallbackRegistry();
        $hashes = [];
        $ids = [];

        $build = static fn (): Elements\Column => Elements\Column::make(
            Elements\Text::make('static')->key('s'),
        );

        $n = 1;
        $build()->toArray($registry, $n, '', 0, $ids, $hashes);

        $n = 1;
        $ids = [];
        $second = $build()->toArray($registry, $n, '', 0, $ids, $hashes);

        // NPHP_NODE_FLAG_REUSE. Note the *root* is reused as well when nothing in the
        // tree changed, so there are no children to inspect — the whole subtree is the
        // marker, which is the point.
        self::assertSame(1, $second['flags']);
        self::assertArrayNotHasKey('children', $second);
    }

    public function testChangedContentDropsTheReuseMarker(): void
    {
        $registry = new CallbackRegistry();
        $hashes = [];
        $ids = [];
        $n = 1;

        Elements\Column::make(Elements\Text::make('before')->key('s'))
            ->toArray($registry, $n, '', 0, $ids, $hashes);

        $n = 1;
        $ids = [];
        $second = Elements\Column::make(Elements\Text::make('after')->key('s'))
            ->toArray($registry, $n, '', 0, $ids, $hashes);

        self::assertArrayNotHasKey('flags', $second['children'][0]);
        self::assertSame('after', $second['children'][0]['props']['text']);
    }

    public function testPressHandlersCrossAsIdsAndAreStable(): void
    {
        $registry = new CallbackRegistry();

        $n1 = 1;
        $n2 = 1;
        $a = Elements\Button::make('Save')->onPress('save')->toArray($registry, $n1);
        $b = Elements\Button::make('Save')->onPress('save')->toArray($registry, $n2);

        self::assertIsInt($a['on_press']);
        self::assertSame($a['on_press'], $b['on_press'], 'The same handler must keep its id across frames.');
        self::assertSame('save', $registry->expression($a['on_press']));
    }

    public function testCallbackIdsMatchUpstreamsScheme(): void
    {
        // The native side resolves these; a different derivation would run the wrong
        // handler rather than fail loudly.
        $mine = new CallbackRegistry();
        $theirs = new \Native\Mobile\Edge\CallbackRegistry();

        foreach (['save', 'increment', 'delete(3)'] as $expression) {
            self::assertSame(
                $theirs->register($expression),
                $mine->register($expression),
                "Callback id for '{$expression}' diverges from upstream.",
            );
        }
    }

    public function testNavigationKeysMatchUpstreamsScheme(): void
    {
        $config = ['to' => '/items/3', 'transition' => 'slide_from_right'];

        $mine = (new CallbackRegistry())->registerNavigation($config);
        $theirs = (new \Native\Mobile\Edge\CallbackRegistry())->registerNavigation($config);

        self::assertSame($theirs, $mine);
    }

    /** Stub PSR-4 autoloader for upstream's Edge namespace. */
    private static function loadUpstream(): bool
    {
        if (self::$upstreamLoaded) {
            return true;
        }

        $root = __DIR__.'/../../upstream/np-mobile/src';

        if (!is_dir($root.'/Edge')) {
            return false;
        }

        spl_autoload_register(static function (string $class) use ($root): void {
            if (!str_starts_with($class, 'Native\\Mobile\\')) {
                return;
            }

            $file = $root.'/'.str_replace('\\', '/', substr($class, \strlen('Native\\Mobile\\'))).'.php';

            if (is_file($file)) {
                require $file;
            }
        });

        return self::$upstreamLoaded = true;
    }
}
