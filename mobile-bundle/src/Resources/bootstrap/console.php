<?php

/**
 * Console entry point — the equivalent of NativePHP Mobile's
 * bootstrap/{ios,android}/artisan.php.
 *
 * The native layer runs console commands through this (migrations on first launch,
 * cache warming, anything an app schedules). A Symfony app has no artisan, so the
 * patcher redirects artisan.php here.
 *
 * The command line arrives in $_SERVER['argv'], set by the host before execution.
 */

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$autoloader = $_SERVER['COMPOSER_AUTOLOADER_PATH'] ?? null;

if (!\is_string($autoloader) || !is_file($autoloader)) {
    $candidate = \dirname(__DIR__, 5).'/autoload.php';
    $autoloader = is_file($candidate) ? $candidate : null;
}

if (null === $autoloader) {
    error_log('[NATIVE_EXCEPTION]: console boot failed — no Composer autoloader.');

    exit(1);
}

require $autoloader;

$projectDir = \dirname(\dirname($autoloader));

// Console commands run in the app's environment, but debug is forced off: a
// device has nowhere useful to render a debug dump, and the profiler would write
// into a cache dir that may be read-only.
$_SERVER['APP_ENV'] ??= $_ENV['APP_ENV'] ?? 'prod';
$_SERVER['APP_DEBUG'] = '0';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'];
$_ENV['APP_DEBUG'] = '0';

if (class_exists(\Symfony\Component\Dotenv\Dotenv::class) && is_file($projectDir.'/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->usePutenv(false)->bootEnv($projectDir.'/.env');
}

$kernelClass = $_SERVER['NATIVEPHP_KERNEL_CLASS'] ?? 'App\Kernel';

try {
    $kernel = new $kernelClass((string) $_SERVER['APP_ENV'], false);

    $application = new Application($kernel);
    $application->setAutoExit(false);

    $status = $application->run(new ArgvInput(), new ConsoleOutput());

    error_log(sprintf('[NativePHP] console exited with %d', $status));

    exit($status);
} catch (\Throwable $e) {
    error_log('[NATIVE_EXCEPTION]: console failed — '.$e->getMessage());
    error_log('[NATIVE_EXCEPTION] trace: '.$e->getTraceAsString());

    exit(1);
}
