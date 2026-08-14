<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every element type, byte-compared against upstream's equivalent.
 *
 * The type-by-type comparison exists because reading is not enough: the inventory that
 * produced this test found two defaults a careful read had missed — ScrollView's
 * overflow=2 and Circle's border_radius=9999 — and a scroll_view without its default
 * lays out like a plain column and silently does not scroll.
 *
 * Skips when the upstream checkout is absent.
 */
final class UiElementCoverageTest extends TestCase
{
    private static bool $loaded = false;

    public function testEveryUpstreamTypeIsImplemented(): void
    {
        if (!self::loadUpstream()) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        $mine = (new ElementFactory())->types();
        $missing = array_values(array_diff(self::upstreamTypes(), $mine));

        self::assertSame([], $missing, 'Element types with no implementation: '.implode(', ', $missing));
    }

    public function testNoInventedTypes(): void
    {
        if (!self::loadUpstream()) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        // A type the renderers do not know produces a missing region on the device and
        // no error anywhere, so an extra one is as bad as a missing one.
        $extra = array_values(array_diff((new ElementFactory())->types(), self::upstreamTypes()));

        self::assertSame([], $extra, 'Types unknown to upstream: '.implode(', ', $extra));
    }

    #[DataProvider('elementTypes')]
    public function testABareElementMatchesUpstreamExactly(string $type, string $upstreamClass): void
    {
        if (!self::loadUpstream()) {
            self::markTestSkipped('Upstream mobile sources not available.');
        }

        $n1 = 1;
        $mine = (new ElementFactory())->create($type)->toArray(new CallbackRegistry(), $n1);

        $n2 = 1;
        $theirs = $upstreamClass::make()->toArray(new \Native\Mobile\Edge\CallbackRegistry(), $n2);

        // Compare everything except the hash first, so a mismatch says *what* differs
        // rather than only that a hash did.
        self::assertSame(
            array_diff_key($theirs, ['_hash' => null]),
            array_diff_key($mine, ['_hash' => null]),
            sprintf('Node for "%s" diverges from upstream.', $type),
        );

        self::assertSame($theirs['_hash'], $mine['_hash'], sprintf('Content hash for "%s" diverges.', $type));
    }

    /** @return iterable<string, array{string, string}> */
    public static function elementTypes(): iterable
    {
        $root = __DIR__.'/../../upstream/np-mobile/src/Edge/Elements';

        if (!is_dir($root)) {
            yield 'upstream unavailable' => ['column', 'Native\Mobile\Edge\Elements\Column'];

            return;
        }

        foreach (glob($root.'/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'Native\Mobile\Edge\Elements\\'.$short;

            // Only the parameterless ones can be compared bare; the rest are covered by
            // the targeted tests in UiWireFormatTest.
            if (!self::hasParameterlessMake($class, $file)) {
                continue;
            }

            $type = self::declaredType($file);

            if (null === $type || 'pressable' === $type && 'Fab' === $short) {
                continue;
            }

            yield $type => [$type, $class];
        }
    }

    private static function hasParameterlessMake(string $class, string $file): bool
    {
        // Read rather than reflect: the provider runs before setUp, so upstream may not
        // be autoloadable yet.
        $source = (string) file_get_contents($file);

        return (bool) preg_match('/public static function make\(\s*\)/', $source);
    }

    private static function declaredType(string $file): ?string
    {
        preg_match("/protected string \\\$type = '([a-z_]+)'/", (string) file_get_contents($file), $m);

        return $m[1] ?? null;
    }

    /** @return list<string> */
    private static function upstreamTypes(): array
    {
        $types = [];

        foreach (glob(__DIR__.'/../../upstream/np-mobile/src/Edge/Elements/*.php') ?: [] as $file) {
            $type = self::declaredType($file);

            if (null !== $type) {
                $types[$type] = true;
            }
        }

        $names = array_keys($types);
        sort($names);

        return $names;
    }

    private static function loadUpstream(): bool
    {
        if (self::$loaded) {
            return true;
        }

        $root = __DIR__.'/../../upstream/np-mobile/src';

        if (!is_dir($root.'/Edge')) {
            return false;
        }

        spl_autoload_register(static function (string $class) use ($root): void {
            if ('Illuminate\Support\Facades\Log' === $class) {
                eval('namespace Illuminate\Support\Facades; class Log { public static function __callStatic($m, $a) {} }');

                return;
            }

            if (!str_starts_with($class, 'Native\\Mobile\\')) {
                return;
            }

            $file = $root.'/'.str_replace('\\', '/', substr($class, \strlen('Native\\Mobile\\'))).'.php';

            if (is_file($file)) {
                require $file;
            }
        });

        return self::$loaded = true;
    }
}
