<?php

declare(strict_types=1);

namespace Native\Symfony\System;

use Native\Symfony\Contract\ClientInterface;

/**
 * GET /api/process — details of the **Electron** process, not the PHP one.
 */
final class RuntimeInfo
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** @return array{pid: int, platform: string, arch: string, uptime: float} */
    public function get(): array
    {
        $data = $this->client->get('process')->array();

        return [
            'pid' => (int) ($data['pid'] ?? 0),
            'platform' => (string) ($data['platform'] ?? ''),
            'arch' => (string) ($data['arch'] ?? ''),
            'uptime' => (float) ($data['uptime'] ?? 0.0),
        ];
    }
}
