<?php

/**
 * Requirement 4: a router script for PHP's built-in server.
 *
 * Laravel ships one at
 *   vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
 * and the runtime hardcodes that path (php.ts, ~L388). Symfony has no
 * equivalent — `symfony serve` uses its own Go binary, which the runtime can't
 * use because it needs to own the process and pass -d ini flags. So we ship
 * this, and the upstream fix is a manifest key naming the router.
 *
 * Invoked with cwd = public/.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$publicDir = __DIR__;
$candidate = $publicDir.$uri;

// Never let a traversal escape the document root.
$real = realpath($candidate);

// The separator matters: without it a sibling directory sharing the prefix —
// /app/public-x next to /app/public — passes the containment check. PHP's built-in
// server does its own docroot resolution on `return false` and refuses, so this is
// not reachable today; it becomes an arbitrary read the moment the router is reused
// under any other SAPI, which is exactly the kind of assumption worth not shipping.
if ('/' !== $uri && false !== $real && str_starts_with($real, $publicDir.\DIRECTORY_SEPARATOR) && is_file($real)) {
    // Returning false hands the request back to the built-in server, which
    // streams the file itself with correct headers.
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicDir.'/index.php';

require $publicDir.'/index.php';
