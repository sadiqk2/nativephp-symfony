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
