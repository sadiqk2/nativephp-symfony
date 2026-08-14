<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Runtime;

use Symfony\Component\HttpFoundation\Request;

/**
 * Builds a Request from the superglobals the mobile runtime hands us.
 *
 * There is no web server here. PHP is compiled into the app and the native layer
 * populates $_SERVER directly before executing a bootstrap script, so several
 * things a SAPI would normally do have to be done by hand — and Laravel's
 * bootstrap/{ios,android}/native.php does exactly this, with comments explaining
 * each one. This is the same work for Symfony.
 */
final class ServerRequestFactory
{
    /**
     * @param array<string, mixed> $server Normally $_SERVER
     *
     * @return array{0: Request, 1: array<string, string>} The request, and the cookies
     *                                                     that had to be reconstructed
     */
    public static function fromServer(array $server, ?string $rawBody = null): array
    {
        $cookies = self::parseCookies($server);
        $query = self::parseQuery($server);

        // Read the body before Request::create, since php://input is a one-shot
        // stream on some SAPIs and Symfony may want it again for JSON.
        $rawBody ??= self::readInput();

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');

        // Form bodies must be parsed into request parameters; anything else
        // (JSON, multipart, raw uploads) is left untouched for the application to
        // read, exactly as the Laravel bootstrap is careful to do.
        $parameters = [];
        $contentType = (string) ($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? '');

        if (\in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && str_starts_with($contentType, 'application/x-www-form-urlencoded')
            && '' !== $rawBody
        ) {
            parse_str($rawBody, $parameters);
        }

        $request = Request::create(
            uri: self::absoluteUri($uri, $server),
            method: $method,
            parameters: $parameters,
            cookies: $cookies,
            files: [],
            server: self::normaliseServer($server),
            content: $rawBody,
        );

        // Request::create() rebuilds the query from the URI, but the native layer
        // supplies QUERY_STRING separately and it is authoritative — it carries
        // query parameters even on POSTs, which the URI may not.
        if ([] !== $query) {
            $request->query->replace($query);
        }

        return [$request, $cookies];
    }

    /** @return array<string, string> */
    private static function parseCookies(array $server): array
    {
        $header = $server['HTTP_COOKIE'] ?? null;

        if (!\is_string($header) || '' === $header) {
            return [];
        }

        $cookies = [];

        // Split on ';' rather than '; ' — a client that omits the space would
        // otherwise silently lose every cookie after the first.
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', trim($pair), 2);

            if (2 === \count($parts) && '' !== $parts[0]) {
                $cookies[$parts[0]] = urldecode($parts[1]);
            }
        }

        return $cookies;
    }

    /** @return array<string, mixed> */
    private static function parseQuery(array $server): array
    {
        $queryString = $server['QUERY_STRING'] ?? null;

        if (!\is_string($queryString) || '' === $queryString) {
            return [];
        }

        parse_str($queryString, $query);

        return $query;
    }

    private static function readInput(): string
    {
        $input = @file_get_contents('php://input');

        return \is_string($input) ? $input : '';
    }

    /**
     * Request::create() needs an absolute URI to derive scheme, host and port.
     * The native layer has no notion of a host, so a stable placeholder is used —
     * consistently, because it ends up in generated URLs.
     */
    private static function absoluteUri(string $uri, array $server): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');

        return 'http://'.$host.'/'.ltrim($uri, '/');
    }

    /** @return array<string, mixed> */
    private static function normaliseServer(array $server): array
    {
        // Request::create() fabricates its own defaults; keeping the native
        // layer's values means headers it set (Accept, X-Requested-With, a
        // session cookie) survive into the Request.
        $server['REQUEST_TIME'] ??= time();
        $server['REQUEST_TIME_FLOAT'] ??= microtime(true);

        return $server;
    }
}
