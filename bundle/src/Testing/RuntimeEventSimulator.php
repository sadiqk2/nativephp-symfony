<?php

declare(strict_types=1);

namespace Native\Symfony\Testing;

use Native\Symfony\EventBridge\EventFactory;
use Native\Symfony\Http\EventsController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The reverse channel (CONTRACT.md §12) without a device: pushes an event into
 * the app exactly as the runtime's `POST /_native/api/events` would.
 *
 * Deliberately routed through the real {@see EventsController} rather than
 * dispatching a hand-built event object, because the parts that break are all in
 * that path and a simulator that skipped them would prove nothing:
 *
 *  - the payload is JSON-encoded and decoded again, so a test gets the same
 *    types the wire produces;
 *  - {@see EventFactory}'s dual spreading applies — a list payload lands on the
 *    constructor positionally, a string-keyed one as named arguments. An app
 *    that mixed the two up would pass a test that skipped this and fail on a
 *    device;
 *  - a caller-named event still goes out under both `native.{name}` and
 *    NativeEvent::class, which is how shortcut and menu listeners are wired.
 *
 * `dispatch()` returns nothing on purpose: the runtime discards the app's answer
 * (`notifyLaravel` has an empty catch), so there is no outcome to hand back. What
 * a test asserts on is what its own listener did.
 */
final class RuntimeEventSimulator
{
    private readonly EventsController $controller;
    private readonly EventFactory $factory;

    /**
     * @param EventFactory|null $factory Pass the app's own — configured with its
     *                                   `events.allowed_namespaces` — when testing
     *                                   events the app dispatches by class name
     */
    public function __construct(EventDispatcherInterface $dispatcher, ?EventFactory $factory = null)
    {
        $this->factory = $factory ?? new EventFactory();
        $this->controller = new EventsController($this->factory, $dispatcher);
    }

    /**
     * @param string                  $event   Runtime event name, e.g.
     *                                         'Native\Desktop\Events\Windows\WindowResized'.
     *                                         Unknown names are legitimate: shortcuts,
     *                                         menu items and notifications all let the
     *                                         app choose the name
     * @param array<array-key, mixed> $payload A list to spread positionally, a
     *                                         string-keyed array to spread as named
     *                                         arguments — as the runtime sends them
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $this->controller->__invoke(Request::create(
            '/_native/api/events',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['event' => $event, 'payload' => $payload]),
        ));
    }

    /**
     * The event object a payload would produce, without dispatching it.
     *
     * For asserting on the mapping itself — that a payload really reaches the
     * constructor argument you think it does.
     *
     * @param array<array-key, mixed> $payload
     */
    public function make(string $event, array $payload = []): object
    {
        return $this->factory->create($event, $payload);
    }

    // --- the events apps actually listen for ---------------------------------
    //
    // Named wrappers exist because the payload *shape* is per-event and not
    // guessable (CONTRACT.md §12): WindowResized is a positional list, ProcessExited
    // is a named object, and ProcessSpawned is positional while every other
    // ChildProcess event is named. Encoding that here once means an app's test
    // cannot silently simulate a shape the runtime never sends.

    public function windowResized(string $id, int $width, int $height): void
    {
        $this->dispatch(self::name('Windows\WindowResized'), [$id, $width, $height]);
    }

    public function windowFocused(string $id): void
    {
        $this->dispatch(self::name('Windows\WindowFocused'), [$id]);
    }

    public function windowBlurred(string $id): void
    {
        $this->dispatch(self::name('Windows\WindowBlurred'), [$id]);
    }

    public function windowClosed(string $id): void
    {
        $this->dispatch(self::name('Windows\WindowClosed'), [$id]);
    }

    /**
     * @param bool $asObject The runtime sends a list from macOS `open-url` and an
     *                       object from the Windows/Linux `second-instance` path.
     *                       Both are real; a deeplink handler should survive either
     */
    public function openedFromUrl(string $url, bool $asObject = false): void
    {
        $this->dispatch(self::name('App\OpenedFromURL'), $asObject ? ['url' => $url] : [$url]);
    }

    public function fileOpened(string $path): void
    {
        $this->dispatch(self::name('App\OpenFile'), [$path]);
    }

    /** @param array<string, mixed> $combo */
    public function menuItemClicked(string $id, ?string $label = null, ?bool $checked = null, array $combo = []): void
    {
        $this->dispatch(self::name('Menu\MenuItemClicked'), [
            'item' => ['id' => $id, 'label' => $label ?? $id, 'checked' => $checked],
            'combo' => $combo,
        ]);
    }

    /** Positional — the one ChildProcess event that is (CONTRACT.md §12). */
    public function processSpawned(string $alias, int $pid): void
    {
        $this->dispatch(self::name('ChildProcess\ProcessSpawned'), [$alias, $pid]);
    }

    public function processExited(string $alias, ?int $code = 0): void
    {
        $this->dispatch(self::name('ChildProcess\ProcessExited'), ['alias' => $alias, 'code' => $code]);
    }

    public function processMessageReceived(string $alias, string $data): void
    {
        $this->dispatch(self::name('ChildProcess\MessageReceived'), ['alias' => $alias, 'data' => $data]);
    }

    public function notificationClicked(string $reference, ?string $event = null): void
    {
        $this->dispatch(self::name('Notifications\NotificationClicked'), [
            'reference' => $reference,
            'event' => $event,
        ]);
    }

    public function notificationReplied(string $reference, string $reply, ?string $event = null): void
    {
        $this->dispatch(self::name('Notifications\NotificationReply'), [
            'reference' => $reference,
            'reply' => $reply,
            'event' => $event,
        ]);
    }

    public function settingChanged(string $key, mixed $value): void
    {
        $this->dispatch(self::name('Settings\SettingChanged'), ['key' => $key, 'value' => $value]);
    }

    /** @param 'on-ac'|'on-battery' $state */
    public function powerStateChanged(string $state): void
    {
        $this->dispatch(self::name('PowerMonitor\PowerStateChanged'), ['state' => $state]);
    }

    public function screenLocked(): void
    {
        $this->dispatch(self::name('PowerMonitor\ScreenLocked'));
    }

    /**
     * A global shortcut firing.
     *
     * Caller-named, so it arrives as a NativeEvent under `native.{$event}` —
     * whatever name the app registered with GlobalShortcutManager. Payload is the
     * pressed key, positional.
     */
    public function shortcutPressed(string $event, string $key): void
    {
        $this->dispatch($event, [$key]);
    }

    /** The runtime's namespace, which is the wire protocol and not a local class. */
    private static function name(string $suffix): string
    {
        return 'Native\\Desktop\\Events\\'.$suffix;
    }
}
