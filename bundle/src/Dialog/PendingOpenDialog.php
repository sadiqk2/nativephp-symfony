<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Dialog;

use Native\Symfony\Desktop\Enums\DialogProperty;

final class PendingOpenDialog extends PendingDialog
{
    public function files(): self
    {
        return $this->addProperty(DialogProperty::OpenFile);
    }

    public function directories(): self
    {
        return $this->addProperty(DialogProperty::OpenDirectory);
    }

    public function multiple(): self
    {
        return $this->addProperty(DialogProperty::MultiSelections);
    }

    public function showHiddenFiles(): self
    {
        return $this->addProperty(DialogProperty::ShowHiddenFiles);
    }

    /**
     * Show the dialog and block until the user answers.
     *
     * @return list<string> The chosen paths; empty when the user cancelled —
     *                      the runtime answers `{result: undefined}`, which
     *                      arrives as a missing key rather than an empty list.
     */
    public function open(): array
    {
        // Default to picking files: an open dialog with no properties at all is
        // valid to Electron but useless, and every caller means one or the other.
        if (!isset($this->payload['properties'])) {
            $this->files();
        }

        $result = $this->client->post('dialog/open', $this->payload)->value('result');

        return \is_array($result) ? array_values(array_map('strval', $result)) : [];
    }

    /** Convenience for the common single-file case. */
    public function openOne(): ?string
    {
        return $this->open()[0] ?? null;
    }
}
