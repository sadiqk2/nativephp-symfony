<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\EventBridge;

use Native\Symfony\Desktop\Event\App;
use Native\Symfony\Desktop\Event\AutoUpdater;
use Native\Symfony\Desktop\Event\ChildProcess;
use Native\Symfony\Desktop\Event\Menu;
use Native\Symfony\Desktop\Event\MenuBar;
use Native\Symfony\Desktop\Event\NativeEvent;
use Native\Symfony\Desktop\Event\Notifications;
use Native\Symfony\Desktop\Event\PowerMonitor;
use Native\Symfony\Desktop\Event\Settings;
use Native\Symfony\Desktop\Event\Windows;

/**
 * Turns a runtime event push into an object to dispatch.
 *
 * Two behaviours are load-bearing and easy to get wrong:
 *
 * 1. **Dual argument spreading.** The runtime pushes list payloads for some
 *    events (WindowResized: [id, width, height]) and string-keyed objects for
 *    others (ProcessExited: {alias, code}). PHP's `...` unpacks a list
 *    positionally and a string-keyed array as *named arguments*, which is what
 *    the upstream Laravel controller relies on. Reproduce it exactly or roughly
 *    half the 44 events break. App\OpenedFromURL arrives in both shapes.
 *
 * 2. **Unknown names still dispatch**, as a NativeEvent under the pushed name.
 *
 * Where this deliberately diverges from upstream: Laravel's controller does
 * `class_exists($event) ? new $event(...$payload)` on the request body, which
 * instantiates any autoloadable class with attacker-chosen constructor
 * arguments if the shared secret ever leaks. Here, only the known map and
 * explicitly allowlisted namespaces are constructed; anything else becomes a
 * NativeEvent. Same behaviour for legitimate traffic, no gadget.
 */
final class EventFactory
{
    /**
     * Runtime event name (the wire protocol, upstream's namespace) => local class.
     *
     * All 46 events the runtime pushes. Caller-named events — from global
     * shortcuts, menu items with an `event`, and notification overrides — are
     * additional to these and have no fixed names; they become NativeEvent.
     *
     * @var array<string, class-string>
     */
    private const MAP = [
        // Windows
        'Native\\Desktop\\Events\\Windows\\WindowBlurred' => Windows\WindowBlurred::class,
        'Native\\Desktop\\Events\\Windows\\WindowFocused' => Windows\WindowFocused::class,
        'Native\\Desktop\\Events\\Windows\\WindowShown' => Windows\WindowShown::class,
        'Native\\Desktop\\Events\\Windows\\WindowHidden' => Windows\WindowHidden::class,
        'Native\\Desktop\\Events\\Windows\\WindowClosed' => Windows\WindowClosed::class,
        'Native\\Desktop\\Events\\Windows\\WindowMinimized' => Windows\WindowMinimized::class,
        'Native\\Desktop\\Events\\Windows\\WindowMaximized' => Windows\WindowMaximized::class,
        'Native\\Desktop\\Events\\Windows\\WindowUnmaximized' => Windows\WindowUnmaximized::class,
        'Native\\Desktop\\Events\\Windows\\WindowResized' => Windows\WindowResized::class,
        'Native\\Desktop\\Events\\Windows\\WindowFullscreened' => Windows\WindowFullscreened::class,
        'Native\\Desktop\\Events\\Windows\\WindowUnfullscreened' => Windows\WindowUnfullscreened::class,

        // App
        'Native\\Desktop\\Events\\App\\OpenFile' => App\OpenFile::class,
        'Native\\Desktop\\Events\\App\\OpenedFromURL' => App\OpenedFromURL::class,

        // Menu
        'Native\\Desktop\\Events\\Menu\\MenuItemClicked' => Menu\MenuItemClicked::class,

        // MenuBar
        'Native\\Desktop\\Events\\MenuBar\\MenuBarCreated' => MenuBar\MenuBarCreated::class,
        'Native\\Desktop\\Events\\MenuBar\\MenuBarShown' => MenuBar\MenuBarShown::class,
        'Native\\Desktop\\Events\\MenuBar\\MenuBarHidden' => MenuBar\MenuBarHidden::class,
        'Native\\Desktop\\Events\\MenuBar\\MenuBarClicked' => MenuBar\MenuBarClicked::class,
        'Native\\Desktop\\Events\\MenuBar\\MenuBarRightClicked' => MenuBar\MenuBarRightClicked::class,
        'Native\\Desktop\\Events\\MenuBar\\MenuBarDoubleClicked' => MenuBar\MenuBarDoubleClicked::class,
        'Native\\Desktop\\Events\\MenuBar\\MenuBarDroppedFiles' => MenuBar\MenuBarDroppedFiles::class,

        // Notifications
        'Native\\Desktop\\Events\\Notifications\\NotificationClicked' => Notifications\NotificationClicked::class,
        'Native\\Desktop\\Events\\Notifications\\NotificationActionClicked' => Notifications\NotificationActionClicked::class,
        'Native\\Desktop\\Events\\Notifications\\NotificationReply' => Notifications\NotificationReply::class,
        'Native\\Desktop\\Events\\Notifications\\NotificationClosed' => Notifications\NotificationClosed::class,

        // ChildProcess
        'Native\\Desktop\\Events\\ChildProcess\\ProcessSpawned' => ChildProcess\ProcessSpawned::class,
        'Native\\Desktop\\Events\\ChildProcess\\ProcessExited' => ChildProcess\ProcessExited::class,
        'Native\\Desktop\\Events\\ChildProcess\\MessageReceived' => ChildProcess\MessageReceived::class,
        'Native\\Desktop\\Events\\ChildProcess\\ErrorReceived' => ChildProcess\ErrorReceived::class,
        'Native\\Desktop\\Events\\ChildProcess\\StartupError' => ChildProcess\StartupError::class,

        // PowerMonitor
        'Native\\Desktop\\Events\\PowerMonitor\\PowerStateChanged' => PowerMonitor\PowerStateChanged::class,
        'Native\\Desktop\\Events\\PowerMonitor\\ThermalStateChanged' => PowerMonitor\ThermalStateChanged::class,
        'Native\\Desktop\\Events\\PowerMonitor\\SpeedLimitChanged' => PowerMonitor\SpeedLimitChanged::class,
        'Native\\Desktop\\Events\\PowerMonitor\\ScreenLocked' => PowerMonitor\ScreenLocked::class,
        'Native\\Desktop\\Events\\PowerMonitor\\ScreenUnlocked' => PowerMonitor\ScreenUnlocked::class,
        'Native\\Desktop\\Events\\PowerMonitor\\Shutdown' => PowerMonitor\Shutdown::class,
        'Native\\Desktop\\Events\\PowerMonitor\\UserDidBecomeActive' => PowerMonitor\UserDidBecomeActive::class,
        'Native\\Desktop\\Events\\PowerMonitor\\UserDidResignActive' => PowerMonitor\UserDidResignActive::class,

        // Settings
        'Native\\Desktop\\Events\\Settings\\SettingChanged' => Settings\SettingChanged::class,

        // AutoUpdater
        'Native\\Desktop\\Events\\AutoUpdater\\CheckingForUpdate' => AutoUpdater\CheckingForUpdate::class,
        'Native\\Desktop\\Events\\AutoUpdater\\UpdateAvailable' => AutoUpdater\UpdateAvailable::class,
        'Native\\Desktop\\Events\\AutoUpdater\\UpdateNotAvailable' => AutoUpdater\UpdateNotAvailable::class,
        'Native\\Desktop\\Events\\AutoUpdater\\UpdateCancelled' => AutoUpdater\UpdateCancelled::class,
        'Native\\Desktop\\Events\\AutoUpdater\\UpdateDownloaded' => AutoUpdater\UpdateDownloaded::class,
        'Native\\Desktop\\Events\\AutoUpdater\\DownloadProgress' => AutoUpdater\DownloadProgress::class,
        'Native\\Desktop\\Events\\AutoUpdater\\Error' => AutoUpdater\Error::class,
    ];

    /** @param list<string> $allowedNamespaces */
    public function __construct(private readonly array $allowedNamespaces = [])
    {
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return object The event to dispatch
     */
    public function create(string $name, array $payload = []): object
    {
        // Names arrive with a leading backslash from some runtime modules and
        // without from others (index.ts and helper/index.ts escape it, window.ts
        // does not). Normalise before any lookup.
        $name = ltrim($name, '\\');

        $class = self::MAP[$name] ?? $this->allowlistedClass($name);

        if (null === $class) {
            return new NativeEvent($name, $payload);
        }

        try {
            /* @phpstan-ignore-next-line — the dual spread is the point; see the class docblock. */
            return new $class(...$payload);
        } catch (\Throwable $e) {
            // A payload shape the class cannot accept is a contract mismatch —
            // a runtime upgrade changed a payload, or the map is wrong. Degrade
            // to the generic event rather than 500ing the endpoint and having
            // the runtime silently swallow it (notifyLaravel has an empty catch).
            return new NativeEvent($name, [...$payload, '__error' => $e->getMessage()]);
        }
    }

    /** @return class-string|null */
    private function allowlistedClass(string $name): ?string
    {
        if ([] === $this->allowedNamespaces) {
            return null;
        }

        foreach ($this->allowedNamespaces as $namespace) {
            $prefix = rtrim($namespace, '\\').'\\';

            if (str_starts_with($name, $prefix) && class_exists($name)) {
                /* @var class-string $name */
                return $name;
            }
        }

        return null;
    }

    /** @return array<string, class-string> */
    public static function knownEvents(): array
    {
        return self::MAP;
    }
}
