<?php

declare(strict_types=1);

namespace App\Mobile;

use Native\Symfony\Mobile\Ui\Component\NativeAction;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\Elements;
use Native\Symfony\Mobile\Ui\Routing\NativeScreen;
use Native\Symfony\Mobile\Ui\Style\StyleParser;

/**
 * A native screen: SwiftUI on iOS, Jetpack Compose on Android. No WebView, no HTML.
 *
 * Three things this file is meant to show.
 *
 * **State is a typed property.** `$count` survives between taps because the PHP
 * process is long-lived on device — the persistent runtime boots the kernel once and
 * serves many interactions, so there is nothing to serialise and nothing to
 * rehydrate. This is the one place mobile is structurally *easier* than the web.
 *
 * **Only `#[NativeAction]` methods can be invoked.** An interaction arrives as a
 * numeric callback id; the id is a lookup key into a registry, and the expression it
 * resolves to is checked against this allowlist. Nothing from the device ever
 * becomes a method name — which is why `reset()` below is not callable even though
 * it is public.
 *
 * **The path is in Laravel placeholder syntax on purpose.** `#[NativeScreen]` paths
 * are copied verbatim into the app bundle and matched on device by Kotlin's and
 * Swift's BootPlanner. A Symfony-flavoured pattern here would mean the two sides
 * disagree about which paths boot natively — visible as a WebView flash on a device
 * and as nothing at all in a test suite.
 */
#[NativeScreen('/counter')]
final class CounterScreen extends NativeComponent
{
    private int $count = 0;

    private array $history = [];

    public function __construct(private readonly StyleParser $styles)
    {
    }

    #[NativeAction]
    public function increment(): void
    {
        $this->bump(1);
    }

    /**
     * Arguments baked into the expression — `onPress('addMany(10)')` — arrive here as
     * PHP arguments. Values from the interaction payload can follow them, and the
     * arity is checked at dispatch, not at registration: how many values an event
     * carries is not known until it happens.
     */
    #[NativeAction]
    public function addMany(int $howMany): void
    {
        $this->bump($howMany);
    }

    /** Public, and deliberately *not* callable from the device: no attribute. */
    public function reset(): void
    {
        $this->count = 0;
        $this->history = [];
    }

    public function count(): int
    {
        return $this->count;
    }

    protected function render(): Element
    {
        $rows = [
            // These three nodes hash the same on every frame, so a re-render publishes
            // them as reuse markers (`flags: 1`) rather than repainting them. That is
            // the whole point of keeping the previous frame's hashes.
            Elements\Text::make('Taps')->fontSize(13)->color('#64748b'),
            Elements\Text::make((string) $this->count)->fontSize(48)->fontWeight('bold'),

            Elements\Row::make(
                Elements\Button::make('+1')->onPress('increment'),
                Elements\Button::make('+10')->onPress('addMany(10)'),
            )->layout($this->styles->parse('gap-3')),

            Elements\Divider::make(),
            Elements\Text::make('History')->fontSize(13)->color('#64748b'),
        ];

        foreach ($this->history as $line) {
            $rows[] = Elements\Text::make($line)->fontSize(14);
        }

        $rows[] = Elements\Spacer::make();

        return Elements\Column::make(...$rows)
            ->layout($this->styles->parse('flex-1 p-4 gap-2'));
    }

    private function bump(int $by): void
    {
        $this->count += $by;
        $this->history[] = sprintf('+%d → %d', $by, $this->count);
        $this->history = \array_slice($this->history, -4);
    }
}
