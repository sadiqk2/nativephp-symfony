<?php

declare(strict_types=1);

namespace Native\Symfony\Contract;

/**
 * Optional companion to AppBootstrapper. Whatever this returns is merged over
 * the runtime's own defaults (memory_limit=512M, curl.cainfo, openssl.cafile)
 * and passed as -d flags to every PHP process the runtime spawns.
 *
 * Same name and shape as the upstream Laravel contract, deliberately.
 *
 * @see \Native\Symfony\Command\PhpIniCommand
 */
interface ProvidesPhpIni
{
    /** @return array<string, scalar> */
    public function phpIni(): array;
}
