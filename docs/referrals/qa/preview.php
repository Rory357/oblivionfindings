<?php

// Owns a disposable test schema and a loopback application. No real records or delivery.
declare(strict_types=1);
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

$root = dirname(__DIR__, 3);
chdir($root);
require $root.'/vendor/autoload.php';
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $key = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'oblivion_referrals_preview', 'TEST_TOKEN' => (string) getmypid(), 'APP_CONFIG_CACHE' => __DIR__.'/unused-config.php', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$fixture = new class('referralsPreview') extends TestCase {};
$app = $fixture->createApplication();
$database = config('database.connections.mysql.database');
if ($database !== 'oblivion_referrals_preview_'.getmypid()) {
    throw new RuntimeException('Preview requires its exact process-owned schema.');
}
Artisan::call('migrate', ['--force' => true]);
$configuration = ReferralSettings::defaults();
$configuration['public'] = [...$configuration['public'], 'enabled' => true, 'provider_name' => 'Oblivion Care — preview', 'contact_phone' => '0800 000 000', 'notice_version' => 'preview-1', 'notice' => 'Demonstration only. Use fictional information. This enquiry is stored in an isolated preview and is removed when the preview closes. No messages are sent.'];
ReferralSettingsVersion::create(['version' => 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);

$process = new Process([PHP_BINARY, '-S', '127.0.0.1:8766', '-t', $root.'/public', __DIR__.'/router.php'], $root, [...$_ENV, 'REFERRALS_PREVIEW_DATABASE' => $database]);
$process->setTimeout(null);
$process->start();
register_shutdown_function(fn () => $process->stop());
echo json_encode(['url' => 'http://127.0.0.1:8766', 'database' => $database, 'pid' => getmypid()]).PHP_EOL;
while ($process->isRunning()) {
    echo $process->getIncrementalErrorOutput();
    usleep(200000);
}
