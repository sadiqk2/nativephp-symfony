<?php

declare(strict_types=1);

namespace Native\Symfony\Window;

/**
 * The runtime's WindowData payload, typed.
 *
 * 21 fields — `frame`, `titleBarStyle` and `trafficLightPosition` are commented
 * out in the runtime's getWindowData() and are deliberately not exposed here.
 *
 * `id` is the developer-assigned string key, not Electron's numeric window id.
 */
final class Window
{
    public function __construct(
        public readonly string $id,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly string $title,
        public readonly string $url,
        public readonly bool $alwaysOnTop,
        public readonly bool $autoHideMenuBar,
        public readonly bool $fullscreen,
        public readonly bool $fullscreenable,
        public readonly bool $kiosk,
        public readonly bool $devToolsOpen,
        public readonly bool $resizable,
        public readonly bool $movable,
        public readonly bool $minimizable,
        public readonly bool $maximizable,
        public readonly bool $closable,
        public readonly bool $focusable,
        public readonly bool $focused,
        public readonly bool $hasShadow,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromRuntime(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            x: (int) ($data['x'] ?? 0),
            y: (int) ($data['y'] ?? 0),
            width: (int) ($data['width'] ?? 0),
            height: (int) ($data['height'] ?? 0),
            title: (string) ($data['title'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            alwaysOnTop: (bool) ($data['alwaysOnTop'] ?? false),
            autoHideMenuBar: (bool) ($data['autoHideMenuBar'] ?? false),
            fullscreen: (bool) ($data['fullscreen'] ?? false),
            fullscreenable: (bool) ($data['fullscreenable'] ?? false),
            kiosk: (bool) ($data['kiosk'] ?? false),
            devToolsOpen: (bool) ($data['devToolsOpen'] ?? false),
            resizable: (bool) ($data['resizable'] ?? false),
            movable: (bool) ($data['movable'] ?? false),
            minimizable: (bool) ($data['minimizable'] ?? false),
            maximizable: (bool) ($data['maximizable'] ?? false),
            closable: (bool) ($data['closable'] ?? false),
            focusable: (bool) ($data['focusable'] ?? false),
            focused: (bool) ($data['focused'] ?? false),
            hasShadow: (bool) ($data['hasShadow'] ?? false),
        );
    }
}
