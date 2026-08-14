<?php

declare(strict_types=1);

namespace Native\Symfony\Client;

/**
 * Thrown when a native API is used outside the runtime — from an ordinary web
 * request, a plain CLI invocation, or a test. NATIVEPHP_API_URL is only set on
 * processes the runtime itself spawns.
 */
final class RuntimeNotAvailable extends \LogicException
{
    public static function forEndpoint(string $endpoint): self
    {
        return new self(sprintf(
            'Cannot call the NativePHP runtime endpoint "%s": NATIVEPHP_API_URL is not set, so this '.
            'process was not started by the runtime. Guard with ClientInterface::isAvailable() if this '.
            'code also runs as a plain web request.',
            $endpoint,
        ));
    }
}
