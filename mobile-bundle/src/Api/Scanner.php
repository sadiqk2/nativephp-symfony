<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Barcode and QR scanning.
 *
 * Opens the native scanner screen, which owns the camera until it closes. The codes
 * come back as events rather than as a return value, so a continuous scan reports
 * each code as it is read instead of a list at the end.
 */
final class Scanner
{
    /**
     * The event names upstream's PendingScanner sends. The hosts use them only as
     * labels to echo back, so nothing here has to be able to load them.
     */
    public const CODE_SCANNED = 'Native\\Mobile\\Events\\Scanner\\CodeScanned';
    public const SCANNER_CANCELLED = 'Native\\Mobile\\Events\\Scanner\\ScannerCancelled';

    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /**
     * Open the scanner.
     *
     * Asynchronous: a true means the scanner was presented. Each code read arrives as
     * the event named by $event; a scanner the user closes without scanning reports
     * {@see self::SCANNER_CANCELLED} instead.
     *
     * @param string|null  $prompt     Shown on the scanner screen; upstream's wording when absent
     * @param bool         $continuous Keep scanning instead of closing on the first code
     * @param list<string> $formats    Any of qr, ean13, ean8, code128, code39, upca, upce, all
     * @param string|null  $id         Echoed back in the event so a listener can tell which scan
     *                                 answered. Generated when absent, as upstream does, rather
     *                                 than left off the wire
     */
    public function scan(?string $prompt = null, bool $continuous = false, array $formats = ['qr'], ?string $id = null, string $event = self::CODE_SCANNED): bool
    {
        // The formats are reindexed for the same reason the alert's buttons are: a gap-
        // or string-keyed array encodes as a JSON object, and a scanner handed an object
        // where it expects a list of formats has no format left to scan for.
        return $this->bridge->dispatch('Scanner.Scan', [
            'prompt' => $prompt ?? 'Scan QR Code',
            'continuous' => $continuous,
            'formats' => array_values($formats),
            'id' => $id ?? bin2hex(random_bytes(8)),
            'event' => $event,
        ]);
    }
}
