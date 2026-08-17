<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Dialog;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Enums\DialogProperty;

/**
 * Shared builder for dialog/open and dialog/save.
 *
 * Both are **synchronous on the runtime side** (showOpenDialogSync /
 * showSaveDialogSync), so the runtime's event loop is stalled while the dialog is
 * up: no other API request is served, and no events are delivered, until the user
 * answers. That is why the client's timeout is an hour.
 *
 * Null and unset keys are stripped by the runtime before the Electron call, so
 * anything you do not set is simply absent.
 */
abstract class PendingDialog
{
    /** @var array<string, mixed> */
    protected array $payload = [];

    public function __construct(protected readonly ClientInterface $client)
    {
    }

    public function title(string $title): static
    {
        $this->payload['title'] = $title;

        return $this;
    }

    public function buttonLabel(string $label): static
    {
        $this->payload['buttonLabel'] = $label;

        return $this;
    }

    /** macOS only: text shown above the file list. */
    public function message(string $message): static
    {
        $this->payload['message'] = $message;

        return $this;
    }

    public function defaultPath(string $path): static
    {
        $this->payload['defaultPath'] = $path;

        return $this;
    }

    /**
     * @param array<string, list<string>> $filters Label => extensions, without dots.
     *                                             e.g. ['Images' => ['png', 'jpg']]
     */
    public function filters(array $filters): static
    {
        $this->payload['filters'] = array_map(
            static fn (string $name, array $extensions): array => [
                'name' => $name,
                'extensions' => $extensions,
            ],
            array_keys($filters),
            $filters,
        );

        return $this;
    }

    /** Make the dialog modal to a window. Unknown ids fall back to app-modal. */
    public function attachedTo(string $windowId): static
    {
        $this->payload['windowReference'] = $windowId;

        return $this;
    }

    public function properties(DialogProperty ...$properties): static
    {
        $this->payload['properties'] = array_values(array_unique(array_map(
            static fn (DialogProperty $p): string => $p->value,
            $properties,
        )));

        return $this;
    }

    protected function addProperty(DialogProperty $property): static
    {
        $existing = $this->payload['properties'] ?? [];
        $existing[] = $property->value;
        $this->payload['properties'] = array_values(array_unique($existing));

        return $this;
    }

    /** @return array<string, mixed> Exposed for assertions in tests. */
    public function payload(): array
    {
        return $this->payload;
    }
}
