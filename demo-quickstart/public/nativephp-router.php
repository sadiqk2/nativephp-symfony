<?php

/**
 * Requirement 4: a router script for PHP's built-in server. Byte-for-byte the same
 * file as demo/public/nativephp-router.php — see its comment for why this exists and
 * what it guards against.
 *
 * Invoked with cwd = public/.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$publicDir = __DIR__;
$candidate = $publicDir.$uri;

$real = realpath($candidate);

if ('/' !== $uri && false !== $real && str_starts_with($real, $publicDir.\DIRECTORY_SEPARATOR) && is_file($real)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicDir.'/index.php';

require $publicDir.'/index.php';
