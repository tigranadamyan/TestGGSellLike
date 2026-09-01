<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server.
 *
 * The container serves through `php -S … server.php` rather than `artisan serve`
 * on purpose: `serve` spawns the built-in server with a whitelist of environment
 * variables (ServeCommand::$passthroughVariables) and drops everything else, so
 * DB_* and REDIS_* injected by Docker or the host never reach the process that
 * handles requests — it silently falls back to whatever .env says.
 *
 * Returning false lets the built-in server serve an existing file from the
 * document root; everything else goes through the framework.
 */
$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
