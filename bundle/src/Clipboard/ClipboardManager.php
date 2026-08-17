<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Clipboard;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Enums\ClipboardType;

/**
 * The system clipboard — 7 endpoints.
 *
 * The buffer is selected with a *query* parameter, not a body field, which is why
 * every method takes an optional type rather than the runtime defaulting it.
 * `Selection` is the X11 primary selection and is ignored elsewhere.
 */
final class ClipboardManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function text(ClipboardType $type = ClipboardType::Clipboard): string
    {
        return (string) $this->client->get('clipboard/text', ['type' => $type->value])->value('text', '');
    }

    public function setText(string $text, ClipboardType $type = ClipboardType::Clipboard): void
    {
        $this->client->post('clipboard/text?type='.$type->value, ['text' => $text]);
    }

    public function html(ClipboardType $type = ClipboardType::Clipboard): string
    {
        return (string) $this->client->get('clipboard/html', ['type' => $type->value])->value('html', '');
    }

    public function setHtml(string $html, ClipboardType $type = ClipboardType::Clipboard): void
    {
        $this->client->post('clipboard/html?type='.$type->value, ['html' => $html]);
    }

    /** @return string|null A PNG data URL, or null when the clipboard holds no image */
    public function image(ClipboardType $type = ClipboardType::Clipboard): ?string
    {
        $image = $this->client->get('clipboard/image', ['type' => $type->value])->value('image');

        return \is_string($image) && '' !== $image ? $image : null;
    }

    /**
     * @param string $dataUrl A data: URL. The runtime answers 400 for anything
     *                        nativeImage cannot parse, including a bare base64 string.
     */
    public function setImage(string $dataUrl, ClipboardType $type = ClipboardType::Clipboard): bool
    {
        return $this->client->post('clipboard/image?type='.$type->value, ['image' => $dataUrl])->successful();
    }

    public function clear(ClipboardType $type = ClipboardType::Clipboard): void
    {
        $this->client->delete('clipboard?type='.$type->value);
    }
}
