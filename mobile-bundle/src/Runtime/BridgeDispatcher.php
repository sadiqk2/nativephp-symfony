<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Runtime;

use Symfony\Component\HttpFoundation\Response;

/**
 * Handles per-request dispatch from the native bridge on iOS and Android.
 *
 * Replaces the old per-request C eval string. The C side evals a single line:
 *
 *   \Native\Symfony\Mobile\Runtime\BridgeDispatcher::handle(
 *       'ios'|'android', 'persistent'|'webview',
 *       '<method>', '<uri>', '<script>', '<cookie>', '<content_type>', '<headers>'
 *   );
 *
 * where all request values are base64-encoded to prevent string parsing flaws.
 * The body is read from php://input (or $input in tests).
 */
final class BridgeDispatcher
{
    public static string $input = 'php://input';
    public static int $bufferFloor = 0;

    /**
     * Serve one request and echo the raw HTTP response.
     */
    public static function handle(
        string $platform,
        string $lane,
        string $method,
        string $uri,
        string $scriptPath,
        string $cookie,
        string $contentType,
        string $headers,
    ): void {
        while (ob_get_level() > self::$bufferFloor) {
            ob_end_clean();
        }

        try {
            [$head, $content] = self::serve(
                $platform,
                $lane,
                self::decode('method', $method),
                self::decode('uri', $uri),
                self::decode('scriptPath', $scriptPath),
                self::decode('cookie', $cookie),
                self::decode('contentType', $contentType),
                self::decode('headers', $headers),
            );
        } catch (\Throwable $e) {
            [$head, $content] = self::errorResponse($lane, $e);
        }

        echo $head;
        echo $content;
    }

    /**
     * Everything handle() does, returning the raw HTTP response string.
     */
    public static function dispatch(
        string $platform,
        string $lane,
        string $method,
        string $uri,
        string $scriptPath,
        string $cookie = '',
        string $contentType = '',
        string $headers = '',
        string $body = '',
    ): string {
        try {
            [$head, $content] = self::serve(
                $platform,
                $lane,
                self::decode('method', $method),
                self::decode('uri', $uri),
                self::decode('scriptPath', $scriptPath),
                self::decode('cookie', $cookie),
                self::decode('contentType', $contentType),
                self::decode('headers', $headers),
                $body,
            );
        } catch (\Throwable $e) {
            [$head, $content] = self::errorResponse($lane, $e);
        }

        return $head.$content;
    }

    /**
     * @return array{0: string, 1: string} [head, content]
     */
    private static function serve(
        string $platform,
        string $lane,
        string $method,
        string $uri,
        string $scriptPath,
        string $cookie,
        string $contentType,
        string $headers,
        ?string $explicitBody = null,
    ): array {
        $body = $explicitBody ?? (@file_get_contents(self::$input) ?: '');

        if ('persistent' === $lane) {
            foreach (getenv() as $key => $value) {
                if (!str_starts_with($key, 'HTTP_') && 'CONTENT_TYPE' !== $key && 'CONTENT_LENGTH' !== $key) {
                    $_SERVER[$key] = $value;
                }
            }
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SCRIPT_FILENAME'] = $scriptPath;
        $_SERVER['PHP_SELF'] = '/native.php';
        $_SERVER['SERVER_NAME'] = '127.0.0.1';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['APP_URL'] = 'ios' === $platform ? 'php://127.0.0.1' : 'http://127.0.0.1';
        $_SERVER['NATIVEPHP_RUNNING'] = 'true';
        $_SERVER['NATIVEPHP_PLATFORM'] = $platform;
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_SERVER['REQUEST_TIME'] = (int) $_SERVER['REQUEST_TIME_FLOAT'];
        $_SERVER['HTTP_HOST'] = '127.0.0.1';

        if ('' !== $cookie) {
            $_SERVER['HTTP_COOKIE'] = $cookie;
        }

        if ('' !== $contentType) {
            $_SERVER['CONTENT_TYPE'] = $contentType;
            $_SERVER['HTTP_CONTENT_TYPE'] = $contentType;
        } elseif (isset($_SERVER['HTTP_CONTENT_TYPE'])) {
            $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];
        }

        $length = \strlen($body);
        if ($length > 0 || isset($_SERVER['HTTP_CONTENT_LENGTH'])) {
            $_SERVER['CONTENT_LENGTH'] = (string) $length;
            if (isset($_SERVER['HTTP_CONTENT_LENGTH'])) {
                $_SERVER['HTTP_CONTENT_LENGTH'] = (string) $length;
            }
        }

        if ('' !== $headers) {
            foreach (explode("\r\n", $headers) as $line) {
                $colon = strpos($line, ':');
                if (false !== $colon) {
                    $name = trim(substr($line, 0, $colon));
                    $value = trim(substr($line, $colon + 1));
                    $headerKey = 'HTTP_'.str_replace('-', '_', strtoupper($name));
                    $_SERVER[$headerKey] = $value;
                }
            }
        }

        $queryAt = strpos($uri, '?');
        $_SERVER['QUERY_STRING'] = false === $queryAt ? '' : substr($uri, $queryAt + 1);

        [$request] = ServerRequestFactory::fromServer($_SERVER, $body);

        $obLevel = ob_get_level();
        ob_start();
        try {
            $response = MobileRuntime::dispatch($request);
            $stray = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }

        $content = $stray.(string) $response->getContent();
        $head = self::formatHead($response, \strlen($content));

        return [$head, $content];
    }

    private static function formatHead(Response $response, int $contentLength): string
    {
        $code = $response->getStatusCode();
        $text = Response::$statusTexts[$code] ?? match ($code) {
            419 => 'Page Expired',
            default => 'Unknown',
        };

        $head = "HTTP/1.1 {$code} {$text}\r\n";

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            $lower = strtolower((string) $name);
            if ('content-length' === $lower || 'transfer-encoding' === $lower) {
                continue;
            }

            foreach ($values as $value) {
                $head .= $name.': '.str_replace(["\r", "\n", "\0"], '', (string) $value)."\r\n";
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $head .= 'Set-Cookie: '.str_replace(["\r", "\n", "\0"], '', (string) $cookie)."\r\n";
        }

        return $head."content-length: {$contentLength}\r\n\r\n";
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function errorResponse(string $lane, \Throwable $e): array
    {
        $prefix = 'webview' === $lane ? 'Webview' : 'Persistent';
        $content = "{$prefix} dispatch error: ".$e->getMessage()."\n".$e->getTraceAsString();
        $head = "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\nContent-Length: ".\strlen($content)."\r\n\r\n";

        return [$head, $content];
    }

    private static function decode(string $name, string $value): string
    {
        if ('' === $value) {
            return '';
        }

        $decoded = base64_decode($value, true);
        if (false === $decoded) {
            throw new \InvalidArgumentException("Bridge argument [{$name}] is not valid base64.");
        }

        return $decoded;
    }
}

// Compatibility alias for unpatched runtimes that expect the upstream namespace
if (!class_exists('Native\\Mobile\\Http\\Bridge\\BridgeDispatcher', false)) {
    class_alias(BridgeDispatcher::class, 'Native\\Mobile\\Http\\Bridge\\BridgeDispatcher');
}
