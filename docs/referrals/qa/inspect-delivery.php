<?php

// Read-only recovery inspection, restricted to the fictional disposable preview.
declare(strict_types=1);

use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$database = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !preg_match('/\Aoblivion_referrals_preview_[1-9][0-9]*\z/', $database)) throw new RuntimeException('Disposable preview required.');
$root = dirname(__DIR__, 3);
chdir($root);
require $root.'/vendor/autoload.php';
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $key = (string) $entry['name']; $value = (string) $entry['value'];
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['APP_ENV' => 'local', 'DB_DATABASE' => $database, 'APP_CONFIG_CACHE' => __DIR__.'/unused-config.php', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'REFERRAL_EMAIL_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath(__DIR__.'/runtime');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== $database) throw new RuntimeException('Preview connection mismatch.');
$version = ReferralSettingsVersion::where('state', 'published')->orderByDesc('version')->firstOrFail();
if (!str_contains($version->configuration['public']['notice'], 'Demonstration only.')) throw new RuntimeException('Fictional publication required.');
echo json_encode([
    'rows' => ReferralOutbox::where('deduplication_key', 'like', 'qa-%')->get()->map(fn ($row) => ['kind' => $row->kind, 'state' => $row->state, 'attempts' => $row->attempts, 'sent_at' => $row->sent_at, 'delivered_at' => $row->delivered_at]),
    'internal_notices' => DB::table('notifications')->where('data->kind', 'referral_intake')->count(),
]).PHP_EOL;
