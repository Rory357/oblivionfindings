<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * Router script for use with PHP's built-in web server (`php -S`).
 *
 * When `php -S` receives a request:
 *   - If the request maps to a real static file under `public/`, this script
 *     returns `false` and PHP serves the file directly with a proper MIME
 *     type (CSS, JS, images, fonts, etc.).
 *   - Otherwise we route the request through `public/index.php` so Laravel
 *     handles it.
 *
 * Without this router, `php -S 127.0.0.1:4173 -t public public/index.php`
 * routes EVERY request through index.php, which serves a 200 + the Inertia
 * shell HTML even for `/build/assets/app.css` — breaking Vite asset loading
 * and causing Playwright tests to see a blank React mount.
 *
 * Used by Playwright in `playwright.config.ts` as:
 *
 *     php -S 127.0.0.1:4173 -t public server.php
 */
$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

// Serve static files directly (so PHP applies proper MIME types).
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
