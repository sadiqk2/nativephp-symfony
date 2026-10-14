<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Runtime\BridgeDispatcher;
use Native\Symfony\Mobile\Runtime\MobileRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class BridgeDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        MobileRuntime::reset();
    }

    public function testBridgeDispatcherServesRequestAndFormatsResponse(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('handle')
            ->willReturnCallback(static function (Request $request): Response {
                return new Response('Hello from '.$request->getPathInfo(), 200, ['content-type' => 'text/plain']);
            });

        MobileRuntime::boot($kernel);

        $response = BridgeDispatcher::dispatch(
            platform: 'android',
            lane: 'persistent',
            method: base64_encode('GET'),
            uri: base64_encode('/api/hello'),
            scriptPath: base64_encode('/path/to/native.php'),
            headers: base64_encode("X-Custom: test-val\r\nAccept: text/plain"),
            body: '',
        );

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
        self::assertStringContainsString("content-type: text/plain\r\n", $response);
        self::assertStringContainsString("content-length: 21\r\n", $response);
        self::assertStringEndsWith("\r\n\r\nHello from /api/hello", $response);
    }

    public function testBridgeDispatcherHandlesPostWithBody(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('handle')
            ->willReturnCallback(static function (Request $request): Response {
                return new Response('Received: '.$request->getContent(), 201);
            });

        MobileRuntime::boot($kernel);

        $response = BridgeDispatcher::dispatch(
            platform: 'ios',
            lane: 'webview',
            method: base64_encode('POST'),
            uri: base64_encode('/submit'),
            scriptPath: base64_encode('/path/to/native.php'),
            body: '{"foo":"bar"}',
        );

        self::assertStringStartsWith("HTTP/1.1 201 Created\r\n", $response);
        self::assertStringContainsString("content-length: 23\r\n", $response);
        self::assertStringEndsWith("\r\n\r\nReceived: {\"foo\":\"bar\"}", $response);
    }

    public function testBridgeDispatcherCapturesStrayOutput(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('handle')
            ->willReturnCallback(static function (): Response {
                echo 'stray log message';

                return new Response('Response body', 200);
            });

        MobileRuntime::boot($kernel);

        $response = BridgeDispatcher::dispatch(
            platform: 'android',
            lane: 'persistent',
            method: base64_encode('GET'),
            uri: base64_encode('/stray'),
            scriptPath: base64_encode('/path/to/native.php'),
        );

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
        self::assertStringContainsString('stray log messageResponse body', $response);
    }

    public function testBridgeDispatcherEmits500WhenRuntimeNotBooted(): void
    {
        MobileRuntime::reset();

        $response = BridgeDispatcher::dispatch(
            platform: 'android',
            lane: 'persistent',
            method: base64_encode('GET'),
            uri: base64_encode('/fail'),
            scriptPath: base64_encode('/path/to/native.php'),
        );

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $response);
        self::assertStringContainsString('Persistent dispatch error:', $response);
    }

    public function testBridgeDispatcherRejectsInvalidBase64(): void
    {
        MobileRuntime::reset();

        $response = BridgeDispatcher::dispatch(
            platform: 'android',
            lane: 'persistent',
            method: '!!!not-base-64!!!',
            uri: base64_encode('/fail'),
            scriptPath: base64_encode('/path/to/native.php'),
        );

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $response);
        self::assertStringContainsString('Bridge argument [method] is not valid base64', $response);
    }
}
