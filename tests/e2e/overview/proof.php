<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// Run with Herd PHP. Keeps its disposable database alive while the local preview runs.
require dirname(__DIR__, 3).'/vendor/autoload.php';

$root = dirname(__DIR__, 3);
chdir($root);
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $key = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'oblivion_overview_proof', 'DB_URL' => '', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/overview-proof-'.getmypid().'.php', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'log', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'overview_proof_session', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => '', 'APP_URL' => 'http://127.0.0.1:8777'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

final class OverviewProofBootstrap extends TestCase
{
    public function preview(): void {}

    public function prepare(): Application
    {
        return $this->createApplication();
    }
}

$app = (new OverviewProofBootstrap('preview'))->prepare();
$database = (string) config('database.connections.mysql.database');
if ($database !== 'oblivion_overview_proof_'.getmypid()) {
    throw new RuntimeException('Preview failed closed: the disposable database was not selected.');
}
$instant = '2026-10-10T21:42:00Z'; // Sunday, 11 October, 10:42 NZDT — the reference mockup's day.
Carbon::setTestNow(CarbonImmutable::parse($instant)->utc());
CarbonImmutable::setTestNow(CarbonImmutable::parse($instant)->utc());
file_put_contents($root.'/storage/app/overview-proof/runtime.json', json_encode(['database' => $database, 'ownerPid' => getmypid(), 'instant' => $instant], JSON_THROW_ON_ERROR));

$server = new Process([PHP_BINARY, '-S', '127.0.0.1:8777', '-t', $root.'/public', __DIR__.'/server.php'], $root, timeout: null);
$server->start();
file_put_contents($root.'/storage/app/overview-proof/runtime.json', json_encode(['database' => $database, 'ownerPid' => getmypid(), 'serverPid' => $server->getPid(), 'instant' => $instant], JSON_THROW_ON_ERROR));
echo "SERVER READY: Run tests/e2e/overview/seed.php to add the fictional records.\n";
register_shutdown_function(fn () => $server->stop());
$server->wait(function ($type, $buffer) use ($root) {
    file_put_contents($root.'/storage/logs/overview-proof-server.log', $buffer, FILE_APPEND);
});
