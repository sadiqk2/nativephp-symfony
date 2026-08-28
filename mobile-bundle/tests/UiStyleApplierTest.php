<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Elements;
use Native\Symfony\Mobile\Ui\Style\StyleApplier;
use PHPUnit\Framework\TestCase;

/**
 * The applier is what makes the parser matter. Without it, camelCase keys reach the wire
 * and every renderer ignores them — the classes appear to do nothing, with no error.
 */
final class UiStyleApplierTest extends TestCase
{
    public function testLayoutKeysAreTranslatedToTheirWireNames(): void
    {
        $node = $this->applied('flex-1 gap-2 items-center');

        self::assertSame([
            'flex_grow' => 1.0,
            'flex_shrink' => 1.0,
            'flex_basis' => 0.0,
            'gap' => 8.0,
            'align_items' => 1,
        ], $node['layout']);

        // The camelCase intermediate names must not survive.
        self::assertArrayNotHasKey('flexGrow', $node['layout']);
    }

    public function testBackgroundIsRenamedNotJustReCased(): void
    {
        // The parser calls it `bg`; the wire calls it `bg_color`.
        self::assertSame(['bg_color' => '#0F172A'], $this->applied('bg-slate-900')['style']);
    }

    public function testStyleAndLayoutGoToDifferentBuckets(): void
    {
        // A renderer applies layout to its Yoga node and style to its view; mixing them
        // would force every renderer to re-partition.
        $node = $this->applied('flex-1 rounded-lg opacity-50');

        self::assertSame(['flex_grow' => 1.0, 'flex_shrink' => 1.0, 'flex_basis' => 0.0], $node['layout']);
        self::assertSame(['border_radius' => 8.0, 'opacity' => 0.5], $node['style']);
    }

    public function testDirectionalPaddingCollapsesIntoOneTuple(): void
    {
        // px-2 py-3 parses to four keys; the wire carries a single padding.
        // Order is top, right, bottom, left.
        self::assertSame([12.0, 8.0, 12.0, 8.0], $this->applied('px-2 py-3')['layout']['padding']);
    }

    public function testUniformPaddingSeedsTheSidesItDoesNotOverride(): void
    {
        self::assertSame([32.0, 16.0, 16.0, 16.0], $this->applied('p-4 pt-8')['layout']['padding']);
    }

    public function testUniformPaddingAloneStaysScalar(): void
    {
        self::assertSame(16.0, $this->applied('p-4')['layout']['padding']);
    }

    public function testFullWidthBecomesAValueNotABoolean(): void
    {
        // w-full is a boolean in the parser's vocabulary and 'fill' on the wire.
        $layout = $this->applied('w-full h-12')['layout'];

        self::assertSame('fill', $layout['width']);
        self::assertSame(48.0, $layout['height']);
    }

    public function testAnExplicitHeightBeatsFullHeightWhicheverWayRoundTheyAreWritten(): void
    {
        // `fillHeight` and `height` are two parser keys writing one wire key, and upstream
        // resolves that collision in a fixed order — the fills are seeded first and an
        // explicit width/height overwrites them (NativeElementCollector::buildLayoutArray).
        // Reading the collision off the order the author happened to type the classes makes
        // the same class string lay out differently here than in the Laravel package.
        self::assertSame(48.0, $this->applied('h-full h-12')['layout']['height']);
        self::assertSame(48.0, $this->applied('h-12 h-full')['layout']['height']);

        self::assertSame(24.0, $this->applied('w-full w-6')['layout']['width']);
        self::assertSame(24.0, $this->applied('w-6 w-full')['layout']['width']);
    }

    public function testTheSafeAreaMaskIsResolvedInUpstreamsOrderNotTheAuthors(): void
    {
        // The three safe-area flags share the one `safe_area` byte, and upstream tests them
        // in the order both/top/bottom, so the bottom-only mask wins any combination. Both
        // edges is what `safe-area` alone already means.
        self::assertSame(3, $this->applied('safe-area-top safe-area-bottom')['layout']['safe_area']);
        self::assertSame(3, $this->applied('safe-area-bottom safe-area-top')['layout']['safe_area']);
        self::assertSame(2, $this->applied('safe-area-top safe-area')['layout']['safe_area']);
        self::assertSame(2, $this->applied('safe-area safe-area-top')['layout']['safe_area']);
    }

    public function testElementPropsReachTheElementUnderTheirWireNames(): void
    {
        $node = $this->applied('text-2xl font-bold', Elements\Text::make('x'));

        self::assertSame(24.0, $node['props']['font_size']);
        self::assertSame(6, $node['props']['font_weight']);
    }

    public function testAnElementPropIsSkippedOnAnElementThatHasNoSuchSetter(): void
    {
        // A font size on a column is meaningless; upstream ignores it the same way.
        $node = $this->applied('text-2xl flex-1');

        self::assertArrayNotHasKey('props', $node);
        self::assertSame(1.0, $node['layout']['flex_grow']);
    }

    public function testABorderColourWithNoWidthIsNotSent(): void
    {
        // The packed node carries one scalar border_width, so a lone colour has
        // nothing to apply to and cannot paint. `border-t` is the shape that
        // produces it: the wire has no per-side width, so the directional class
        // contributes nothing and only the colour survives.
        self::assertArrayNotHasKey('style', $this->applied('border-t border-gray-200'));
    }

    public function testAnAuthoredWidthWithNoColourIsKept(): void
    {
        // Deliberately not symmetric with the case above. Tailwind's `border` means
        // a visible border, and this is upstream-patches/0008: with no theme
        // resolver `border-2 border-theme-primary` resolves to a width and no
        // colour, and upstream dropping that width is the bug we fixed.
        self::assertSame(1.0, $this->applied('border')['style']['border_width']);
        self::assertSame(2.0, $this->applied('border-2 border-theme-primary')['style']['border_width']);
    }

    public function testABorderWithBothHalvesIsSentWhole(): void
    {
        $style = $this->applied('border border-gray-200')['style'];

        self::assertSame(1.0, $style['border_width']);
        self::assertSame('#E5E7EB', $style['border_color']);
    }

    public function testTheNestedDarkCompanionIsNotFlattenedOntoTheWire(): void
    {
        // `dark` is the parser's structure, not a style value. Passing it through would put
        // a nested array where a colour belongs.
        $node = $this->applied('dark:bg-slate-900');

        self::assertArrayNotHasKey('dark', $node['style'] ?? []);
        self::assertArrayNotHasKey('dark', $node['layout'] ?? []);
    }

    public function testUnrecognisedClassesAreReportedRatherThanSwallowed(): void
    {
        $element = Elements\Column::make();
        $dropped = (new StyleApplier())->applyClasses($element, 'flex-1 border-t not-a-class');

        self::assertContains('not-a-class', $dropped);
    }

    public function testApplyingNothingLeavesTheNodeBare(): void
    {
        $node = $this->applied('');

        self::assertSame(['id', 'type', '_hash'], array_keys($node));
    }

    /** @return array<string, mixed> */
    private function applied(string $classes, ?Elements\Text $text = null): array
    {
        $element = $text ?? Elements\Column::make();
        (new StyleApplier())->applyClasses($element, $classes);

        $nextId = 1;

        return $element->toArray(new CallbackRegistry(), $nextId);
    }
}
