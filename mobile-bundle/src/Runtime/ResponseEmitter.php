<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Runtime;

use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a raw HTTP response to stdout for the native layer to parse.
 *
 * There is no SAPI to hand the response to: the Android and iOS hosts execute a
 * PHP script and read its stdout as a complete HTTP message, so the status line,
 * headers and blank separator all have to be written by hand — CRLF, in order.
 *
 * Deliberately does not use Response::send(): that writes headers through
 * header(), which is a no-op in the CLI SAPI, so the native layer would receive a
 * body with no status line and no headers.
 */
final class ResponseEmitter
{
    /**
     * @param array<string, string> $extraHeaders Diagnostics to append, e.g. timings
     *
     * @return string The message written, so callers can capture it in tests
     */
    public function emit(Response $response, array $extraHeaders = [], bool $write = true): string
    {
        $code = $response->getStatusCode();

        // Response::$statusTexts has no entry for a few real codes (419 from
        // Laravel's CSRF layer being the one the upstream bootstrap special-cases).
        // An empty reason phrase is legal HTTP, but a missing one is not.
        $status = Response::$statusTexts[$code] ?? match ($code) {
            419 => 'Page Expired',
            default => 'Unknown',
        };

        $message = "HTTP/1.1 {$code} {$status}\r\n";

        foreach ($extraHeaders as $name => $value) {
            $message .= $name.': '.$this->sanitise($value)."\r\n";
        }

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                $message .= $name.': '.$this->sanitise((string) $value)."\r\n";
            }
        }

        // Cookies live outside the header bag until send() runs, so they have to be
        // emitted explicitly or every session on the device is lost.
        foreach ($response->headers->getCookies() as $cookie) {
            $message .= 'Set-Cookie: '.$cookie."\r\n";
        }

        $message .= "\r\n";

        if ($write) {
            echo $message;

            // Streamed and binary responses must go through their own send path,
            // which writes directly to the output buffer.
            $response->sendContent();

            return $message;
        }

        return $message.(string) $response->getContent();
    }

    /**
     * A header value containing CR or LF would let a response split into two, and
     * the native parser trusts what it reads. Strip rather than escape.
     */
    private function sanitise(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }
}
