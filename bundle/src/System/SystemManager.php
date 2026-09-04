<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\System;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Enums\SystemTheme;

/**
 * OS integration — 11 endpoints: biometrics, OS-keychain encryption, printing and
 * the system theme.
 */
final class SystemManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function canPromptTouchId(): bool
    {
        return (bool) $this->client->get('system/can-prompt-touch-id')->value('result', false);
    }

    /**
     * Prompt for Touch ID (macOS). Blocks until the user responds.
     *
     * @return bool True when authentication succeeded; false on failure *and* on
     *              cancellation — the runtime answers 400 for both, without
     *              distinguishing them.
     */
    public function promptTouchId(string $reason): bool
    {
        return $this->client->post('system/prompt-touch-id', ['reason' => $reason])->successful();
    }

    public function canEncrypt(): bool
    {
        return (bool) $this->client->get('system/can-encrypt')->value('result', false);
    }

    /**
     * Encrypt with the OS keychain (Keychain, DPAPI, libsecret).
     *
     * Bound to the machine *and* the user account: ciphertext produced here cannot
     * be decrypted elsewhere, which makes this unsuitable for anything that has to
     * survive a reinstall or move between machines.
     *
     * @return string|null Base64 ciphertext, or null when encryption is unavailable
     */
    public function encrypt(string $value): ?string
    {
        $response = $this->client->post('system/encrypt', ['string' => $value]);

        if (!$response->successful()) {
            return null;
        }

        $result = $response->value('result');

        return \is_string($result) ? $result : null;
    }

    /** @param string $value Base64 ciphertext from encrypt() */
    public function decrypt(string $value): ?string
    {
        $response = $this->client->post('system/decrypt', ['string' => $value]);

        if (!$response->successful()) {
            return null;
        }

        $result = $response->value('result');

        return \is_string($result) ? $result : null;
    }

    /**
     * Available printers.
     *
     * The runtime reads these from `getAllWindows()[0]`, so this **throws on the
     * runtime side when no window exists** — do not call it before the first window
     * is open.
     *
     * @return list<array<string, mixed>>
     */
    public function printers(): array
    {
        $printers = $this->client->get('system/printers')->value('printers', []);

        return \is_array($printers) ? array_values(array_filter($printers, 'is_array')) : [];
    }

    /**
     * Print an HTML document.
     *
     * Bounded, unlike most calls: the runtime only answers from inside
     * `did-finish-load` -> `webContents.print(cb)`, so a bad data URL or a wedged
     * print subsystem means the callback never fires and nothing ever replies. On
     * the default hour-long timeout that holds a PHP worker for the whole hour. No
     * human is being waited on here, so five minutes is generous.
     *
     * @param string               $printer  A device name from printers()
     * @param array<string, mixed> $settings Electron webContents.print options
     */
    public function print(string $printer, string $html, array $settings = [], int $timeout = 300): bool
    {
        return $this->client->post('system/print', [
            'printer' => $printer,
            'html' => rawurlencode($html),
            'settings' => $settings,
        ], $timeout)->successful();
    }

    /**
     * Print a PDF that already exists on disk.
     *
     * The path is read by the *runtime*, not by this process: it is a path on the
     * machine Electron is running on, and in a packaged app that is the same machine,
     * but nothing here checks that the file exists — an unreadable one comes back as a
     * failed call, not an exception.
     *
     * The runtime parses the PDF's MediaBox to size the page and refuses anything it
     * cannot measure, so this is for PDFs specifically; print() is the route for HTML.
     *
     * Bounded for the same reason as print(), and more generously: the runtime waits
     * 1.5s after load for PDFium to paint before it even starts the job.
     *
     * @param string               $printer  A device name from printers()
     * @param array<string, mixed> $settings Electron webContents.print options, merged
     *                                       over the page size the runtime derived
     */
    public function printFile(string $path, string $printer, array $settings = [], int $timeout = 300): bool
    {
        return $this->client->post('system/print-file', [
            'path' => $path,
            'printer' => $printer,
            'settings' => $settings,
        ], $timeout)->successful();
    }

    /**
     * Render HTML to a PDF.
     *
     * @param array<string, mixed> $settings Electron printToPDF options
     *
     * @return string|null Raw PDF bytes, or null on failure
     */
    public function printToPdf(string $html, array $settings = [], int $timeout = 300): ?string
    {
        $response = $this->client->post('system/print-to-pdf', [
            // The runtime builds a data: URL by string concatenation and its media
            // type is malformed (`data:text/html;base64;charset=UTF-8,` with the
            // payload unencoded), so percent-encode to survive the trip.
            'html' => rawurlencode($html),
            'settings' => $settings,
        ], $timeout);

        $result = $response->value('result');

        if (!\is_string($result) || '' === $result) {
            return null;
        }

        $decoded = base64_decode($result, true);

        return false === $decoded ? null : $decoded;
    }

    public function theme(): SystemTheme
    {
        $theme = (string) $this->client->get('system/theme')->value('result', 'system');

        return SystemTheme::tryFrom($theme) ?? SystemTheme::System;
    }

    public function setTheme(SystemTheme $theme): void
    {
        $this->client->post('system/theme', ['theme' => $theme->value]);
    }
}
