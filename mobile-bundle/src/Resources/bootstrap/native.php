<?php

/**
 * One-shot SAPI shim — the Symfony equivalent of NativePHP Mobile's
 * bootstrap/{ios,android}/native.php.
 *
 * The native layer executes this file by absolute path and reads stdout as a
 * complete HTTP message. There is no web server, no SAPI and no framework
 * bootstrap file: the host populates $_SERVER, sets COMPOSER_AUTOLOADER_PATH, and
 * runs this.
 *
 * Everything here runs before the container exists, so it stays deliberately
 * dependency-free and defensive — a failure at this point has no logger, no error
 * page and no way to reach the developer except error_log(), which surfaces in
 * logcat and the Xcode console.
 *
 * Prefer persistent.php where the host supports it: this path pays for the
 * autoloader and the whole container on every request.
 */

declare(strict_types=1);

use Native\Symfony\Mobile\Runtime\ResponseEmitter;
use Native\Symfony\Mobile\Runtime\ServerRequestFactory;

$started = microtime(true);

// ---------------------------------------------------------------------------
// 1. Autoloader
// ---------------------------------------------------------------------------
$autoloader = $_SERVER['COMPOSER_AUTOLOADER_PATH'] ?? null;

if (!is_string($autoloader) || !is_file($autoloader)) {
    // Fall back to walking up from here: vendor/<vendor>/<pkg>/src/Resources/bootstrap
    $candidate = \dirname(__DIR__, 5).'/autoload.php';
    $autoloader = is_file($candidate) ? $candidate : null;
}

if (null === $autoloader) {
    nativephp_symfony_fail('No Composer autoloader. COMPOSER_AUTOLOADER_PATH was not set and none was found relative to the bootstrap script.');
}

require $autoloader;
$autoloadedAt = microtime(true);

// ---------------------------------------------------------------------------
// 2. Environment
// ---------------------------------------------------------------------------
// The host sets Laravel-shaped variable names; read them, but do not require
// them. APP_ENV/APP_DEBUG come from the app's own .env via Dotenv below.
$projectDir = nativephp_symfony_project_dir($autoloader);

if (class_exists(\Symfony\Component\Dotenv\Dotenv::class) && is_file($projectDir.'/.env')) {
    // Deliberately before the defaults below, and that ordering is the whole point:
    // bootEnv() never overwrites a value already in $_SERVER, so defaulting APP_ENV
    // to prod first meant the app's own .env could never decide it — and the
    // environment it names is also what picks .env.dev, .env.prod and their .local
    // overlays, so every one of those went unread. The host still wins when it sets
    // APP_ENV itself, because that value is in $_SERVER before this line.
    //
    // usePutenv(false): putenv() is process-global and this process outlives the
    // request in persistent mode.
    (new \Symfony\Component\Dotenv\Dotenv())->usePutenv(false)->bootEnv($projectDir.'/.env', 'prod');
}

foreach (['APP_ENV' => 'prod', 'APP_DEBUG' => '0'] as $key => $default) {
    $_SERVER[$key] ??= $_ENV[$key] ?? $default;
    $_ENV[$key] = $_SERVER[$key];
}

// ---------------------------------------------------------------------------
// 3. Kernel
// ---------------------------------------------------------------------------
$kernelClass = $_SERVER['NATIVEPHP_KERNEL_CLASS'] ?? 'App\Kernel';

if (!class_exists($kernelClass)) {
    nativephp_symfony_fail(sprintf(
        'Kernel class "%s" not found. Set NATIVEPHP_KERNEL_CLASS if the application does not use App\Kernel.',
        $kernelClass,
    ));
}

try {
    /** @var \Symfony\Component\HttpKernel\HttpKernelInterface&\Symfony\Component\HttpKernel\KernelInterface $kernel */
    $kernel = new $kernelClass(
        (string) $_SERVER['APP_ENV'],
        filter_var($_SERVER['APP_DEBUG'], \FILTER_VALIDATE_BOOLEAN),
    );

    [$request] = ServerRequestFactory::fromServer($_SERVER);

    $response = $kernel->handle($request);

    $emitter = new ResponseEmitter();
    $emitter->emit($response, [
        // Mirrors the upstream bootstrap's X-PHP-Timing header. On a device this
        // is often the only profiling available.
        'X-PHP-Timing' => sprintf(
            'autoload=%.1fms,total=%.1fms,mode=one-shot',
            ($autoloadedAt - $started) * 1000,
            (microtime(true) - $started) * 1000,
        ),
    ]);

    if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
        $kernel->terminate($request, $response);
    }
} catch (\Throwable $e) {
    nativephp_symfony_fail(sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()), $e);
}

/**
 * Emit a minimal 500 by hand and stop.
 *
 * Used for failures that happen before, or instead of, a usable kernel — at which
 * point ResponseEmitter may not even be autoloadable. The native layer needs a
 * parseable message whatever happened, or it shows a blank screen with no clue.
 */
function nativephp_symfony_fail(string $message, ?\Throwable $previous = null): never
{
    error_log('[NATIVE_EXCEPTION]: '.$message);

    if (null !== $previous) {
        error_log('[NATIVE_EXCEPTION] trace: '.$previous->getTraceAsString());
    }

    $body = "NativePHP for Symfony failed to boot.\n\n".$message."\n";

    echo "HTTP/1.1 500 Internal Server Error\r\n";
    echo "Content-Type: text/plain; charset=UTF-8\r\n";
    echo 'Content-Length: '.\strlen($body)."\r\n";
    echo "\r\n";
    echo $body;

    exit(1);
}

/** Derive the project root from the autoloader path: <root>/vendor/autoload.php. */
function nativephp_symfony_project_dir(string $autoloader): string
{
    $vendorDir = \dirname($autoloader);

    return \dirname($vendorDir);
}
