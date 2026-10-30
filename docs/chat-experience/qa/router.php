<?php

// Loopback front controller for the disposable Chat preview schema only.
declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

$root = dirname(__DIR__, 3);
$database = getenv('CHAT_PREVIEW_DATABASE');
if (PHP_SAPI !== 'cli-server' || ! preg_match('/\Aoblivion_chat_preview_[1-9][0-9]*\z/', $database ?: '')) {
    http_response_code(503);
    exit('Isolated preview unavailable.');
}
$path = realpath($root.'/public/'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path && str_starts_with($path, $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR) && is_file($path)) {
    return false;
}
chdir($root);
foreach (['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8767', 'DB_DATABASE' => $database, 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'chat_preview', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => '', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'file', 'CHAT_SHARED_EXPERIENCE' => 'true'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
// Optional local calling: the loopback engine (deploy/chat-calling/local-windows.ps1) behind
// calling-gateway.mjs. Credentials are read from the engine's ignored local config.
$engineConfig = $root.'/storage/app/chat-local-engine/local.yaml';
if (getenv('CHAT_PREVIEW_CALLING') === '1' && is_file($engineConfig)
    && preg_match('/^keys:\s*\n\s+([0-9a-f]{32}):\s*([0-9a-f]{64})\s*$/m', (string) file_get_contents($engineConfig), $engineKey)) {
    foreach (['CHAT_LIVEKIT_URL' => 'wss://oblivionfindings.test:7443', 'CHAT_LIVEKIT_API_URL' => 'https://oblivionfindings.test:7443',
        'CHAT_LIVEKIT_API_KEY' => $engineKey[1], 'CHAT_LIVEKIT_API_SECRET' => $engineKey[2],
        'CHAT_CALLING_VERIFIED' => 'true', 'CHAT_CALLING_AUTH_GATEWAY_VERIFIED' => 'true'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
define('LARAVEL_START', microtime(true));
require $root.'/vendor/autoload.php';
$storage = __DIR__.'/runtime';
foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/private', 'app/public'] as $directory) {
    if (! is_dir($storage.'/'.$directory)) {
        mkdir($storage.'/'.$directory, 0777, true);
    }
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
// PHP's bundle does not include Herd's local CA; trust it for this preview's engine calls only.
$bundle = __DIR__.'/runtime/ca-bundle.pem';
if (getenv('CHAT_PREVIEW_CALLING') === '1' && is_file($bundle)) {
    Http::globalOptions(['verify' => $bundle]);
}
Mail::fake();
Notification::fake();
$app->handleRequest(Request::capture());
