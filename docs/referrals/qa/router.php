<?php

declare(strict_types=1);
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

$root = dirname(__DIR__, 3);
$database = getenv('REFERRALS_PREVIEW_DATABASE');
if (PHP_SAPI !== 'cli-server' || ! preg_match('/\Aoblivion_referrals_preview_[1-9][0-9]*\z/', $database ?: '')) {
    http_response_code(503);
    exit('Isolated preview unavailable.');
}
$path = realpath($root.'/public/'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path && str_starts_with($path, $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR) && is_file($path)) {
    return false;
}
chdir($root);
foreach (['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8766', 'DB_DATABASE' => $database, 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'referrals_preview', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => '', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
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
// Explicitly opt in only for this disposable database and reviewed fixed fixtures.
// This is a browser-test substitute, not malware protection or launch evidence.
$fixtureSwitch = __DIR__.'/runtime/fixture-scanner-database';
if (is_file($fixtureSwitch) && trim((string) file_get_contents($fixtureSwitch)) === $database
    && DB::connection()->getDatabaseName() === $database) {
    $app->bind(MalwareScanner::class, fn () => new class extends MalwareScanner
    {
        public function scanPath(string $path, array $settings): MalwareScanResult
        {
            $fixtures = [
                'b1d3a480caadc15d78cd19fbb5ac78b332ddb56d6c553c8a18eba8abd576eef6',
                'f5effcdcfa3d657b970e9068f675c15a15355ee11551bc870f32d64e36045471',
            ];
            if (is_file($path) && in_array((string) hash_file('sha256', $path), $fixtures, true)) {
                return new MalwareScanResult(MalwareScanDisposition::Clean, 'fictional-preview-fixed-fixture');
            }

            return parent::scanPath($path, $settings);
        }
    });
}
Mail::fake();
Notification::fake();
$app->make(Vite::class)->useHotFile($root.'/storage/referrals-preview.hot');
$app->handleRequest(Request::capture());
