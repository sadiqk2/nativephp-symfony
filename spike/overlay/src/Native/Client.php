<?php

declare(strict_types=1);

namespace App\Native;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Transport for channel A (app -> runtime).
 *
 * The Laravel original is Native\Desktop\Client\Client — 38 lines on the Http
 * facade. This is the same thing on Symfony's HttpClient. Note the base URI
 * already ends in /api/ (the runtime hands it to us that way via
 * NATIVEPHP_API_URL) and the secret goes on every single request or the
 * runtime's middleware answers 403 before routing.
 */
final class Client
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'default::NATIVEPHP_API_URL')]
        private readonly ?string $apiUrl = null,
        #[Autowire(env: 'default::NATIVEPHP_SECRET')]
        private readonly ?string $secret = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->apiUrl && '' !== $this->apiUrl;
    }

    public function get(string $endpoint, array $query = []): ResponseInterface
    {
        return $this->request('GET', $endpoint, ['query' => $query]);
    }

    public function post(string $endpoint, array $data = []): ResponseInterface
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    public function delete(string $endpoint, array $data = []): ResponseInterface
    {
        return $this->request('DELETE', $endpoint, ['json' => $data]);
    }

    private function request(string $method, string $endpoint, array $options): ResponseInterface
    {
        return $this->http->request($method, rtrim((string) $this->apiUrl, '/').'/'.ltrim($endpoint, '/'), [
            ...$options,
            'headers' => [
                'X-NativePHP-Secret' => (string) $this->secret,
                'Accept' => 'application/json',
            ],
            // Several endpoints block on native UI (dialogs, TouchID) and will
            // not return until the user acts. See CONTRACT.md section 0.
            'timeout' => 3600,
        ]);
    }
}
