<?php

// Apply only the delivery migration to the explicitly named disposable preview.
declare(strict_types=1);

use App\Domain\Referrals\Models\ReferralSettingsVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$database = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !preg_match('/\Aoblivion_referrals_preview_[1-9][0-9]*\z/', $database)) throw new RuntimeException('An explicitly named disposable preview is required.');
$root = dirname(__DIR__, 3);
chdir($root);
require $root.'/vendor/autoload.php';
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $key = (string) $entry['name']; $value = (string) $entry['value'];
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['APP_ENV' => 'local', 'DB_DATABASE' => $database, 'APP_CONFIG_CACHE' => __DIR__.'/unused-config.php', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null', 'REFERRAL_EMAIL_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath(__DIR__.'/runtime');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== $database) throw new RuntimeException('Preview connection mismatch.');
$version = ReferralSettingsVersion::where('state', 'published')->orderByDesc('version')->firstOrFail();
if (!str_contains($version->configuration['public']['notice'], 'Demonstration only.')) throw new RuntimeException('Fictional publication required.');
Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_16_150700_add_referral_delivery_receipts.php', '--force' => true]);
echo Artisan::output();
