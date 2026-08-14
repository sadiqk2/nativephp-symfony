<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Runtime\ResponseEmitter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class ResponseEmitterTest extends TestCase
{
    public function testItWritesAStatusLineHeadersAndABlankSeparator(): void
    {
        $response = new Response('Hello', 200, ['Content-Type' => 'text/html']);

        $message = (new ResponseEmitter())->emit($response, write: false);

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $message);
        self::assertStringContainsString("Content-Type: text/html\r\n", $message);
        self::assertStringContainsString("\r\n\r\nHello", $message);
    }

    public function testCookiesAreEmittedExplicitly(): void
    {
        // Cookies live outside the header bag until send() runs. Relying on that
        // would silently drop every session on the device.
        $response = new Response('ok');
        $response->headers->setCookie(Cookie::create('session', 'abc123'));

        $message = (new ResponseEmitter())->emit($response, write: false);

        self::assertStringContainsString('Set-Cookie: session=abc123', $message);
    }

    public function testAStatusWithNoReasonPhraseStillGetsOne(): void
    {
        // 419 has no entry in Response::$statusTexts, and a missing reason phrase
        // is not legal HTTP — the native parser would see a malformed status line.
        $message = (new ResponseEmitter())->emit(new Response('', 419), write: false);

        self::assertStringStartsWith("HTTP/1.1 419 Page Expired\r\n", $message);
    }

    public function testAnUnknownStatusFallsBackRatherThanEmittingNothing(): void
    {
        $message = (new ResponseEmitter())->emit(new Response('', 599), write: false);

        self::assertStringStartsWith("HTTP/1.1 599 Unknown\r\n", $message);
    }

    public function testHeaderInjectionIsStripped(): void
    {
        // The native parser trusts what it reads, so a CR or LF in a header value
        // would let one response masquerade as two.
        $response = new Response('ok');
        $response->headers->set('X-Evil', "value\r\nX-Injected: yes");

        $message = (new ResponseEmitter())->emit($response, write: false);

        // The property that matters is that no *new header line* appears: the
        // value is flattened onto one line rather than escaped, so the injected
        // text survives as text — harmlessly — while the split does not.
        self::assertStringNotContainsString("\r\nX-Injected:", $message);
        self::assertSame(1, substr_count($message, 'X-Evil:'));
        self::assertSame(0, substr_count($message, "\nX-Injected: yes\r"));
    }

    public function testExtraHeadersComeFirstAndAreAlsoSanitised(): void
    {
        $message = (new ResponseEmitter())->emit(
            new Response('ok'),
            ['X-PHP-Timing' => "total=1ms\r\nX-Bad: 1"],
            write: false,
        );

        self::assertStringNotContainsString("\r\nX-Bad:", $message);
        self::assertSame(1, substr_count($message, 'X-PHP-Timing:'));

        // Extra headers precede the response's own.
        self::assertLessThan(strpos($message, 'Cache-Control:') ?: \PHP_INT_MAX, strpos($message, 'X-PHP-Timing:'));
    }

    public function testHeaderCaseIsPreserved(): void
    {
        // Symfony lowercases header names internally; some native HTTP parsers are
        // case-sensitive about well-known ones.
        $response = new Response('ok', 200, ['X-Custom-Header' => 'v']);

        self::assertStringContainsString('X-Custom-Header: v', (new ResponseEmitter())->emit($response, write: false));
    }

    public function testItActuallyWritesToStdoutWhenAsked(): void
    {
        ob_start();
        (new ResponseEmitter())->emit(new Response('body-here', 201));
        $written = (string) ob_get_clean();

        self::assertStringStartsWith("HTTP/1.1 201 Created\r\n", $written);
        self::assertStringEndsWith('body-here', $written);
    }
}
