<?php

// Loaded only by the owned loopback verification process.
if (getenv('APP_ENV') !== 'testing' || ! str_starts_with((string) getenv('DB_DATABASE'), 'oblivion_findings_codex_test_myday_browser_md_ui_01a094e0')) {
    http_response_code(403);
    exit;
}
$root = dirname(__DIR__, 4);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath($root.'/public'.$path);
if ($file && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $root).'/public/') && is_file($file)) {
    return false;
}
require_once $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Vite::useBuildDirectory('build');
$app->handleRequest(Illuminate\Http\Request::capture());
