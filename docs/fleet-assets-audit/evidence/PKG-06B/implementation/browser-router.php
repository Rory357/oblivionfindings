<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

$root = dirname(__DIR__, 5);
chdir($root);
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$fixture = json_decode(file_get_contents($root.'/storage/framework/pkg06b-browser.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $fixture['database'])) {
    throw new RuntimeException('Unexpected fixture database');
}
$xml = simplexml_load_file($root.'/phpunit.pkg06b.xml');
foreach ($xml->php->env as $entry) {
    $name = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
foreach (['DB_DATABASE' => $fixture['database'], 'APP_URL' => 'http://127.0.0.1:8905', 'SESSION_DRIVER' => 'file'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
header('X-Pkg06b-Source: 8821-implementation');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/storage/sample-logo.png') {
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    readfile($fixture['storage'].'/branding-verification/sample-logo.png');
    exit;
}
$real = realpath($root.'/public'.$path);
if ($real && is_file($real) && str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $root).'/public/')) {
    return false;
}
require_once $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.private.root' => $fixture['storage']]);
config(['filesystems.disks.public.root' => $fixture['storage'].'/branding-verification']);
Storage::forgetDisk('private');
Route::middleware('web')->get('/__pkg06b/sign-in', function () use ($fixture) {
    Auth::loginUsingId($fixture['user']);
    request()->session()->regenerate();

    return redirect('/fleet-assets/assets/'.$fixture['asset']);
});
$app->handleRequest(Request::capture());
