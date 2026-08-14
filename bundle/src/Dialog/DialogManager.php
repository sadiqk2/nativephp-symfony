<?php

declare(strict_types=1);

namespace Native\Symfony\Dialog;

use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Enums\AlertType;

/**
 * dialog/open, dialog/save, alert/message and alert/error — 4 endpoints, all of
 * them blocking the runtime's event loop until the user responds.
 */
final class DialogManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function open(): PendingOpenDialog
    {
        return new PendingOpenDialog($this->client);
    }

    public function save(): PendingSaveDialog
    {
        return new PendingSaveDialog($this->client);
    }

    /**
     * A modal message box.
     *
     * @param list<string> $buttons    Defaults to a single OK button when empty
     * @param int|null     $defaultId  Index of the button bound to Enter
     * @param int|null     $cancelId   Index treated as the cancel action (Escape,
     *                                 window close). Without it, Escape maps to
     *                                 button 0, which can silently mean "yes".
     *
     * @return int Index of the clicked button
     */
    public function alert(
        string $message,
        array $buttons = [],
        AlertType|string|null $type = null,
        ?string $title = null,
        ?string $detail = null,
        ?int $defaultId = null,
        ?int $cancelId = null,
    ): int {
        $payload = array_filter([
            'message' => $message,
            'buttons' => [] === $buttons ? null : array_values($buttons),
            'type' => $type instanceof AlertType ? $type->value : $type,
            'title' => $title,
            'detail' => $detail,
            'defaultId' => $defaultId,
            'cancelId' => $cancelId,
        ], static fn (mixed $v): bool => null !== $v);

        return (int) $this->client->post('alert/message', $payload)->value('result', 0);
    }

    /**
     * Ask a yes/no question. Returns true only for the confirming button.
     *
     * cancelId is bound to the second button so Escape and the window close
     * button both mean "no" — otherwise Electron maps them to index 0.
     */
    public function confirm(
        string $message,
        string $confirmLabel = 'OK',
        string $cancelLabel = 'Cancel',
        ?string $title = null,
        ?string $detail = null,
    ): bool {
        return 0 === $this->alert(
            message: $message,
            buttons: [$confirmLabel, $cancelLabel],
            type: AlertType::Question,
            title: $title,
            detail: $detail,
            defaultId: 0,
            cancelId: 1,
        );
    }

    /**
     * A native error box. Unlike alert(), this works before the app is ready and
     * needs no window, which makes it the only usable way to report a failure
     * that happens during boot.
     */
    public function error(string $title, string $message): void
    {
        $this->client->post('alert/error', ['title' => $title, 'message' => $message]);
    }
}
