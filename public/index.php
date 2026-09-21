<?php

/**
 * ZEF Framework v2.7.0 — HTTP entrypoint (web SAPI).
 *
 * Mirrors the monolith's SECTION 29 entrypoint web branch:
 *   - boots the demo application through \Zef\App\Bootstrap::createApp(),
 *   - converts PHP globals into a PSR-7 request via handleGlobals(),
 *   - emits the PSR-7 response (with Content-Length reconciliation).
 *
 * Development server:
 *   php -S 0.0.0.0:8080 public/index.php
 *   bin/zef --serve 0.0.0.0:8080
 */

declare(strict_types=1);

require __DIR__ . '/../autoload/zef_autoload.php';

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("ZEF Framework requires PHP >= 8.4\n");
}

$debug = filter_var(getenv('ZEF_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);

try {
    $app = \Zef\App\Bootstrap::createApp($debug);
    $response = $app->handleGlobals();
    $app->emit($response);
} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $debug ? get_class($e) . ": " . $e->getMessage() : 'Internal Server Error';
}
