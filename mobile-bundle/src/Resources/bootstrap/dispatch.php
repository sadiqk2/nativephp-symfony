<?php

/**
 * Per-request entry point in persistent mode.
 *
 * Evaluated in a fresh scope by the native layer for each request, after
 * persistent.php has booted the runtime once. Nothing from that scope is visible
 * here, which is why the runtime is reached through a static accessor.
 */

declare(strict_types=1);

use Native\Symfony\Mobile\Runtime\MobileRuntime;
use Native\Symfony\Mobile\Runtime\ServerRequestFactory;

$started = microtime(true);

try {
    $runtime = MobileRuntime::instance();

    [$request] = ServerRequestFactory::fromServer($_SERVER);

    $runtime->emit($request, [
        'X-PHP-Timing' => sprintf(
            'total=%.1fms,mode=persistent,dispatch=%d',
            (microtime(true) - $started) * 1000,
            $runtime->dispatchCount() + 1,
        ),
    ]);
} catch (\Throwable $e) {
    error_log('[NATIVE_EXCEPTION]: dispatch failed — '.$e->getMessage());

    $body = "Dispatch failed.\n\n".$e->getMessage()."\n";

    echo "HTTP/1.1 500 Internal Server Error\r\n";
    echo "Content-Type: text/plain; charset=UTF-8\r\n";
    echo 'Content-Length: '.\strlen($body)."\r\n";
    echo "\r\n";
    echo $body;
}
