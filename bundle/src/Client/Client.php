<?php

declare(strict_types=1);

namespace Native\Symfony\Client;

use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Contract\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Client implements ClientInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $apiUrl,
        private readonly ?string $secret,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function isAvailable(): bool
    {
        return null !== $this->apiUrl && '' !== $this->apiUrl;
    }

    public function get(string $endpoint, array $query = [], ?int $timeout = null): Response
    {
        return $this->request('GET', $endpoint, [] === $query ? [] : ['query' => $query], $timeout);
    }

    public function post(string $endpoint, array $data = [], ?int $timeout = null): Response
    {
        return $this->request('POST', $endpoint, ['json' => $data], $timeout);
    }

    public function delete(string $endpoint, array $data = [], ?int $timeout = null): Response
    {
        return $this->request('DELETE', $endpoint, ['json' => $data], $timeout);
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $endpoint, array $options, ?int $timeout = null): Response
    {
        if (!$this->isAvailable()) {
            throw RuntimeNotAvailable::forEndpoint($endpoint);
        }

        $url = rtrim((string) $this->apiUrl, '/').'/'.ltrim($endpoint, '/');

        try {
            $response = $this->http->request($method, $url, [
                ...$options,
                'headers' => [
                    // Required on every request without exception: the runtime's
                    // middleware answers 403 before routing if it is missing.
                    'X-NativePHP-Secret' => (string) $this->secret,
                    'Accept' => 'application/json',
                ],
                // Dialogs, alerts and TouchID block the runtime's event loop
                // until the user acts, so a short default would abort perfectly
                // healthy calls. Matches the upstream client's 3600s. Callers that
                // are not waiting on a human pass their own: an endpoint that hangs
                // for want of a callback holds a PHP worker for the whole hour.
                'timeout' => $timeout ?? 3600,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw RuntimeCallFailed::transport($method, $endpoint, $e);
        }

        if (403 === $status) {
            throw RuntimeCallFailed::forbidden($method, $endpoint);
        }

        return new Response($status, $this->decode($body, $status, $method, $endpoint));
    }

    /** @return array<string, mixed>|list<mixed>|null */
    private function decode(string $body, int $status, string $method, string $endpoint): ?array
    {
        $body = trim($body);

        if ('' === $body) {
            return null;
        }

        // Most mutations answer via express' res.sendStatus(), which sets the
        // body to the status *phrase* — a 200 arrives as the literal "OK", a 404
        // as "Not Found". That is a bodyless response as far as callers are
        // concerned. Match the phrase exactly rather than "anything that is not
        // JSON", so a genuine HTML error page still gets reported.
        if ($body === (SymfonyResponse::$statusTexts[$status] ?? null)) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Never silently swallow: mistaking "the runtime did not answer" for
            // "the runtime said nothing" is the most expensive class of bug in
            // this system (see SPIKE-RESULTS.md finding 3).
            $this->logger->error('Runtime returned non-JSON for {method} {endpoint}: {error}', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'body' => substr($body, 0, 512),
            ]);

            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
