<?php

/**
 * Persistent SAPI shim — booted once, then reused for every request.
 *
 * The native layer runs this file a single time and keeps the interpreter alive;
 * subsequent requests arrive through Runtime::dispatch(), evaluated in a fresh
 * scope with no reference to anything declared here. That is why the booted
 * runtime is held statically rather than in a local.
 *
 * This is where mobile's speed comes from: the autoloader, the container and every
 * bundle's boot are paid for once instead of per screen.
 */

declare(strict_types=1);

use Native\Symfony\Mobile\Runtime\MobileRuntime;

$started = microtime(true);

$autoloader = $_SERVER['COMPOSER_AUTOLOADER_PATH'] ?? null;

if (!\is_string($autoloader) || !is_file($autoloader)) {
    $candidate = \dirname(__DIR__, 5).'/autoload.php';
    $autoloader = is_file($candidate) ? $candidate : null;
}

if (null === $autoloader) {
    error_log('[NATIVE_EXCEPTION]: persistent boot failed — no Composer autoloader.');
    echo 'BOOT_FATAL: no Composer autoloader';

    exit(1);
}

require $autoloader;

$projectDir = \dirname(\dirname($autoloader));

foreach (['APP_ENV' => 'prod', 'APP_DEBUG' => '0'] as $key => $default) {
    $_SERVER[$key] ??= $_ENV[$key] ?? $default;
    $_ENV[$key] = $_SERVER[$key];
}

if (class_exists(\Symfony\Component\Dotenv\Dotenv::class) && is_file($projectDir.'/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->usePutenv(false)->bootEnv($projectDir.'/.env');
}

$kernelClass = $_SERVER['NATIVEPHP_KERNEL_CLASS'] ?? 'App\Kernel';

try {
    $kernel = new $kernelClass(
        (string) $_SERVER['APP_ENV'],
        filter_var($_SERVER['APP_DEBUG'], \FILTER_VALIDATE_BOOLEAN),
    );

    MobileRuntime::boot($kernel);

    error_log(sprintf(
        'PerfTiming: persistent boot total=%.1fms opcache=%s',
        (microtime(true) - $started) * 1000,
        \function_exists('opcache_get_status') ? 'available' : 'NOT_AVAILABLE',
    ));
} catch (\Throwable $e) {
    // The native layer captures stdout from the boot script, so this string is
    // how a boot failure becomes visible at all.
    error_log('[NATIVE_EXCEPTION]: persistent boot failed — '.$e->getMessage());
    error_log('[NATIVE_EXCEPTION] trace: '.$e->getTraceAsString());

    echo 'BOOT_FATAL: '.$e->getMessage();
}

// The interpreter stays alive from here. Every further request is a
// MobileRuntime::instance()->dispatch(...) call via dispatch.php.
