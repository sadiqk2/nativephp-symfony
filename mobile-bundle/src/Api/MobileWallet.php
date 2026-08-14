<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * Apple Pay and Google Pay.
 *
 * The payment sheet is presented natively and its outcome arrives as an event, so
 * a status must always be read back rather than inferred from the presentation
 * call. Never treat presentPaymentSheet() returning true as a completed payment.
 */
final class MobileWallet
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function isAvailable(): bool
    {
        $result = $this->bridge->call('MobileWallet.IsAvailable');

        return (bool) ($result['available'] ?? false);
    }

    /**
     * @param int    $amount   Minor units (cents), never a float — a float here is
     *                         how rounding bugs get into payments
     * @param string $currency ISO 4217
     *
     * @return array<string, mixed> The created intent, including its id
     */
    public function createPaymentIntent(int $amount, string $currency, array $metadata = []): array
    {
        return $this->bridge->call('MobileWallet.CreatePaymentIntent', [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'metadata' => $metadata,
        ]) ?? [];
    }

    public function presentPaymentSheet(string $intentId): bool
    {
        return $this->bridge->dispatch('MobileWallet.PresentPaymentSheet', ['intentId' => $intentId]);
    }

    /** @return array<string, mixed> */
    public function confirmPayment(string $intentId): array
    {
        return $this->bridge->call('MobileWallet.ConfirmPayment', ['intentId' => $intentId]) ?? [];
    }

    /** @return array<string, mixed> The authoritative outcome — always check this */
    public function paymentStatus(string $intentId): array
    {
        return $this->bridge->call('MobileWallet.GetPaymentStatus', ['intentId' => $intentId]) ?? [];
    }
}
