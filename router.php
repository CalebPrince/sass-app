<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in web server (`php -S ... router.php`),
 * which is what server.py launches.
 *
 * Rule:
 *   * If the request maps to a real file under /public (a CSS/JS asset),
 *     let the built-in server stream it directly (return false).
 *   * Everything else is handed to the application front controller.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // serve the static asset as-is
}

require __DIR__ . '/public/index.php';
