<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\Elements;
use Native\Symfony\Mobile\Ui\Style\StyleApplier;
use Native\Symfony\Mobile\Ui\Style\StyleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Byte-compares a **styled** node — layout, style *and props* — against the one
 * upstream produces for the same classes, across the real corpus.
 *
 * UiWireFormatTest already compares bare elements, and that is exactly why this
 * exists: a comparison only covers what it exercises. Every bug this file was
 * written for lived in the half it skipped. `<image>` sent `source` where the
 * renderers intern `src`, so every image was blank. Per-corner radii went out as
 * `border_radius_top_left`, a name that appears nowhere upstream, so asymmetric
 * rounding rendered square. `safe_area` was a bool where the wire is an edge mask,
 * so top-only and bottom-only insets did nothing and content sat under the notch.
 * Every `dark:` variant was parsed and then dropped. All of them silent, and all of
 * them invisible to a test that stops at the element.
 *
 * Skips when the upstream checkout is absent.
 */
final class UiStyledNodeParityTest extends TestCase
{
    private static bool $upstreamLoaded = false;

    protected function setUp(): void
    {
        if (!self::loadUpstream()) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }
    }

    /**
     * The whole corpus, one element type. Deliberately not a data provider: 1,336
     * cases as separate tests drowns the run, and the useful output is which
     * classes diverge, not how many assertions passed.
     */
    public function testTheCorpusProducesIdenticalStyledNodes(): void
    {
        $divergent = [];

        foreach (self::corpus() as $classes) {
            $mine = $this->mine($classes);
            $theirs = $this->theirs($classes);

            if ($this->normalise($mine, $classes) !== $this->normalise($theirs, $classes)) {
                $divergent[$classes] = ['mine' => $mine, 'upstream' => $theirs];
            }
        }

        self::assertSame(
            [],
            array_keys($divergent),
            sprintf(
                "%d of %d class strings diverge from upstream.\nFirst: %s",
                \count($divergent),
                \count(self::corpus()),
                json_encode(\array_slice($divergent, 0, 2), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wireKeyCases(): iterable
    {
        // The specific keys worth naming, so a regression says what broke rather
        // than only that something did.
        yield 'image src is interned; source is not in the PropKey table' => ['w-10', 'src'];
        yield 'per-corner radii are float props' => ['rounded-t-2xl', 'radius_tl'];
        yield 'dark backgrounds reach the wire' => ['dark:bg-black', 'dark_bg_color'];
        yield 'aspect ratio has a home' => ['aspect-square', 'aspect_ratio'];
        yield 'select-none is a prop the renderers read' => ['select-none', 'selectable'];
    }

    #[DataProvider('wireKeyCases')]
    public function testNamedWireKeysSurvive(string $classes, string $expectedKey): void
    {
        $node = $this->mine($classes, 'image');
        $flat = array_merge($node['props'] ?? [], $node['layout'] ?? [], $node['style'] ?? []);

        self::assertArrayHasKey($expectedKey, $flat, sprintf('"%s" lost %s', $classes, $expectedKey));
    }

    public function testSafeAreaIsAnEdgeMaskRatherThanAFlag(): void
    {
        // 1 both, 2 top, 3 bottom — a u8 in the binary layout, not a boolean.
        self::assertSame(1, $this->mine('safe-area')['layout']['safe_area'] ?? null);
        self::assertSame(2, $this->mine('safe-area-top')['layout']['safe_area'] ?? null);
        self::assertSame(3, $this->mine('safe-area-bottom')['layout']['safe_area'] ?? null);
    }

    public function testAPartialInsetSendsFloatsForTheEdgesItDidNotAuthor(): void
    {
        // A null in a float slot is 0 at best, and shortens the tuple at worst —
        // which shifts the other three edges.
        $position = $this->mine('absolute bottom-0 left-0')['layout']['position'] ?? [];

        self::assertCount(4, $position);
        self::assertContainsOnlyFloat($position);
    }

    /** @return array<string, mixed> */
    private function mine(string $classes, string $type = 'column'): array
    {
        $element = 'image' === $type ? Elements\Image::make('u') : Elements\Column::make();

        (new StyleApplier(new StyleParser()))->applyClasses($element, $classes);

        $nextId = 1;

        return $element->toArray(new CallbackRegistry(), $nextId);
    }

    /** @return array<string, mixed> */
    private function theirs(string $classes): array
    {
        $nextId = 1;

        return \Native\Mobile\Edge\Elements\Column::make()->class($classes)->toArray(new \Native\Mobile\Edge\CallbackRegistry(), $nextId);
    }

    /**
     * Ignores what is legitimately ours: node ids and content hashes are derived
     * from our own key order, which differs from upstream's and is only ever
     * compared against our own previous frame.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function normalise(array $node, string $classes = ''): array
    {
        unset($node['id'], $node['_hash']);

        // The one divergence left standing deliberately. Both parsers emit
        // `flexBasis: 0` for `flex-1`; upstream's element path then drops it and
        // ours keeps it. Checked against the renderers: an explicit basis of 0 with
        // grow > 0 is exactly what they compute for flex-1 anyway (FlexContainer
        // .swift:168, :283), so the frames render identically. Kept out of the
        // comparison rather than "fixed", because removing a correct value to match
        // an omission would be cargo-culting.
        unset($node['layout']['flex_basis']);

        // The other standing divergence, and this one is upstream's bug rather than
        // ours: a theme border with no resolver drops the explicit width along with
        // the colour, so `border-2 border-theme-primary` renders borderless. That is
        // upstream-patches/0008. Comparing these keys on a theme border would pin
        // our output to the behaviour we deliberately fixed.
        if (str_contains($classes, 'border-theme-')) {
            unset($node['style']['border_width'], $node['style']['border_color']);
        }

        // A width with no colour, which we keep and upstream drops. Tailwind's
        // `border` means a visible border, and this is the same call as
        // upstream-patches/0008: `border-2 border-theme-primary` resolves to a
        // width and no colour without a theme resolver, and dropping the width
        // there is upstream's bug. The other half of this — a colour with no width
        // — is no longer a divergence: the bundle drops it, since the packed node
        // has one scalar border_width and there is nothing for a lone colour to
        // apply to.
        if (isset($node['style']['border_width']) && !isset($node['style']['border_color'])) {
            unset($node['style']['border_width']);
        }

        // Per-corner radii. Ours are built exactly as upstream's
        // buildCornerRadiusProps builds them, and both renderers read radius_tl —
        // but upstream's own Element::class() path does not produce them, despite
        // its collector applying them centrally "so every element honors" them
        // (NativeElementCollector.php:1352). That looks like a wiring gap on their
        // side, and matching it would mean rendering square corners on purpose.
        unset(
            $node['props']['radius_tl'], $node['props']['radius_tr'],
            $node['props']['radius_br'], $node['props']['radius_bl'],
        );

        foreach (['layout', 'style', 'props'] as $bucket) {
            if (!isset($node[$bucket]) || !\is_array($node[$bucket])) {
                continue;
            }

            // An exemption that empties a bucket must remove it: the emitters omit
            // the key entirely rather than sending an empty one.
            if ([] === $node[$bucket]) {
                unset($node[$bucket]);

                continue;
            }

            ksort($node[$bucket]);
        }

        return $node;
    }

    /** @return array<string, string> */
    private static function corpus(): array
    {
        /** @var array{classStrings: array<string, mixed>} $data */
        $data = json_decode((string) file_get_contents(__DIR__.'/fixtures/tailwind-corpus.json'), true);

        $strings = array_keys($data['classStrings']);

        return array_combine($strings, $strings);
    }

    private static function loadUpstream(): bool
    {
        if (self::$upstreamLoaded) {
            return true;
        }

        $root = __DIR__.'/../../upstream/np-mobile/src';

        if (!is_dir($root.'/Edge')) {
            return false;
        }

        // TailwindParser needs one stub beyond the Edge classes' own (empty) needs.
        if (!class_exists(\Illuminate\Support\Facades\Log::class, false)) {
            eval('namespace Illuminate\Support\Facades; class Log { public static function __callStatic($m, $a) {} }');
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
