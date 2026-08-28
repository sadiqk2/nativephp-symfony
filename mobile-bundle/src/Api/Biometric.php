<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class Biometric
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /**
     * Prompt for Face ID, Touch ID or Android biometrics.
     *
     * Asynchronous: the outcome arrives as an event, not as a return value. A true
     * here means the prompt was shown, nothing more — treating it as "the user
     * authenticated" would be an authentication bypass.
     *
     * The prompt's wording is not ours to set: the bridge method takes no reason or
     * fallback title, so each OS shows its own copy.
     */
    public function prompt(): bool
    {
        return $this->bridge->dispatch('Biometric.Prompt');
    }
}
