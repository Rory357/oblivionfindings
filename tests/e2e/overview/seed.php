<?php

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\OverviewExtensionVisualFixtures;
use Tests\Support\OverviewVisualFixtures;

// Repeatable fictional data setup, kept separate from the long-lived preview server.
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
chdir($root);
$state = json_decode(file_get_contents($root.'/storage/app/overview-proof/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
if ($state['database'] !== 'oblivion_overview_proof_'.(int) $state['ownerPid']) {
    throw new RuntimeException('Overview seeding requires the guarded preview database.');
}
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $key = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $state['database'], 'DB_URL' => '', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/overview-proof-seed-'.getmypid().'.php', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'log', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$frozen = CarbonImmutable::parse($state['instant'])->utc();
Carbon::setTestNow($frozen);
CarbonImmutable::setTestNow($frozen);
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (($argv[1] ?? '') === '--extensions') {
    DB::transaction(fn () => OverviewExtensionVisualFixtures::seed());
    echo "Fictional planning examples added to the isolated preview.\n";
    exit(0);
}
if (($argv[1] ?? '') === '--repair-preview-login') {
    User::where('email', 'overview@demo.test')->sole()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    echo "Fictional preview login updated to fixture defaults.\n";
    exit(0);
}
if (User::where('email', 'overview@demo.test')->exists()) {
    throw new RuntimeException('The preview is already seeded; existing examples were left intact.');
}
DB::transaction(fn () => OverviewVisualFixtures::seed());
echo "READY: http://127.0.0.1:8777/login — overview@demo.test / password\n";
