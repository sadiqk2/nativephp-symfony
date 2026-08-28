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
     * @param string $currency ISO 4217, lower case on the wire
     *
     * @return array<string, mixed> The created intent, including its id
     */
    public function createPaymentIntent(int $amount, string $currency, array $metadata = []): array
    {
        return $this->bridge->call('MobileWallet.CreatePaymentIntent', [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'metadata' => $metadata,
        ]) ?? [];
    }

    /**
     * The sheet needs the intent's client secret and the merchant's own identity — an
     * intent id alone is not enough to present it, and Stripe's SDK is initialised from
     * the publishable key here rather than from anything native.
     *
     * @param string               $clientSecret        From createPaymentIntent(), not the intent id
     * @param string               $merchantCountryCode ISO 3166-1 alpha-2
     * @param array<string, mixed> $options             Passed through to the sheet
     */
    public function presentPaymentSheet(
        string $clientSecret,
        string $merchantDisplayName,
        string $publishableKey,
        string $merchantId,
        string $merchantCountryCode = 'US',
        array $options = [],
    ): bool {
        return $this->bridge->dispatch('MobileWallet.PresentPaymentSheet', [
            'clientSecret' => $clientSecret,
            'merchantDisplayName' => $merchantDisplayName,
            'publishableKey' => $publishableKey,
            'merchantId' => $merchantId,
            'merchantCountryCode' => $merchantCountryCode,
            'options' => $options,
        ]);
    }

    /** @return array<string, mixed> */
    public function confirmPayment(string $paymentIntentId): array
    {
        return $this->bridge->call('MobileWallet.ConfirmPayment', ['paymentIntentId' => $paymentIntentId]) ?? [];
    }

    /** @return array<string, mixed> The authoritative outcome — always check this */
    public function paymentStatus(string $paymentIntentId): array
    {
        return $this->bridge->call('MobileWallet.GetPaymentStatus', ['paymentIntentId' => $paymentIntentId]) ?? [];
    }
}
