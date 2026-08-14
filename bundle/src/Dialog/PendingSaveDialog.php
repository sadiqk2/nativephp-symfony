<?php

declare(strict_types=1);

namespace Native\Symfony\Dialog;

use Native\Symfony\Enums\DialogProperty;

final class PendingSaveDialog extends PendingDialog
{
    public function showHiddenFiles(): self
    {
        return $this->addProperty(DialogProperty::ShowHiddenFiles);
    }

    public function confirmOverwrite(): self
    {
        return $this->addProperty(DialogProperty::ShowOverwriteConfirmation);
    }

    public function createDirectory(): self
    {
        return $this->addProperty(DialogProperty::CreateDirectory);
    }

    /**
     * Show the dialog and block until the user answers.
     *
     * @return string|null The chosen path, or null when cancelled
     */
    public function save(): ?string
    {
        $result = $this->client->post('dialog/save', $this->payload)->value('result');

        return \is_string($result) && '' !== $result ? $result : null;
    }
}
