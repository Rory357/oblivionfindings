<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$state = json_decode(file_get_contents($root.'/storage/app/overview-proof/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
$database = getenv('DB_DATABASE');
if (! preg_match('/\Aoblivion_overview_proof_[0-9]+\z/', (string) $database)
    || $database !== $state['database'] || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Overview preview requires its own disposable database.');
}
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath($root.'/public'.$uri);
if ($uri !== '/' && $file !== false && str_starts_with($file, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

define('LARAVEL_START', microtime(true));
$frozen = CarbonImmutable::parse($state['instant'])->utc();
Carbon::setTestNow($frozen);
CarbonImmutable::setTestNow($frozen);
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
// Keep transport cookie lifetimes intact against the browser's unfrozen clock.
foreach ($response->headers->getCookies() as $cookie) {
    if ($cookie->getExpiresTime() > $frozen->getTimestamp() && $cookie->getValue() !== null && $cookie->getValue() !== '') {
        $response->headers->setCookie($cookie->withExpires(time() + $cookie->getExpiresTime() - $frozen->getTimestamp()));
    }
}
$response->send();
$kernel->terminate($request, $response);
