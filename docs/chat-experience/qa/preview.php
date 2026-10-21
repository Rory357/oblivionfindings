<?php

// Owns a disposable schema and a loopback application with fictional Chat people.
// Nothing here touches the shared development database or sends real notifications.
declare(strict_types=1);

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatSettings;
use Database\Seeders\RbacSeeder;
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
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'oblivion_chat_preview', 'TEST_TOKEN' => (string) getmypid(), 'APP_CONFIG_CACHE' => __DIR__.'/unused-config.php', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'CHAT_SHARED_EXPERIENCE' => 'true'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$fixture = new class('chatPreview') extends TestCase {};
$app = $fixture->createApplication();
$database = config('database.connections.mysql.database');
if ($database !== 'oblivion_chat_preview_'.getmypid()) {
    throw new RuntimeException('Preview requires its exact process-owned schema.');
}
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);

// Calls are off by default; the preview enables them so the local engine can be exercised.
AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['calls_enabled' => true, 'family_calls_enabled' => true]]);
$runtime = __DIR__.'/runtime';
if (! is_dir($runtime)) {
    mkdir($runtime, 0777, true);
}
$herd = getenv('USERPROFILE').'/.config/herd/config';
if (is_file($herd.'/php/cacert.pem') && is_file($herd.'/valet/CA/LaravelValetCASelfSigned.crt')) {
    file_put_contents($runtime.'/ca-bundle.pem', file_get_contents($herd.'/php/cacert.pem')."\n".file_get_contents($herd.'/valet/CA/LaravelValetCASelfSigned.crt'));
}

$password = 'Chat-preview-only-2026!';
$site = Site::factory()->create(['name' => 'Kowhai House (preview)']);
$client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Aroha', 'last_name' => 'Preview', 'status' => 'active']);
$staff = function (string $key, string $name, string $role) use ($site, $password): User {
    $user = User::factory()->withoutTwoFactor()->create(['name' => $name, 'email' => $key.'@chat-preview.test', 'password' => $password, 'role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::where('name', $role)->sole()->id]);
    HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);

    return $user;
};
$lead = $staff('lead', 'Mere Lead', 'team_lead');
$worker = $staff('worker', 'Rohan Worker', 'support_worker');
$client->supportWorkers()->attach($worker->id);
$relative = User::factory()->withoutTwoFactor()->create(['name' => 'Jo Relative', 'email' => 'family@chat-preview.test', 'password' => $password, 'role' => 'next_of_kin', 'approved_at' => now()]);
$relative->roles()->syncWithoutDetaching([Role::where('name', 'next_of_kin')->sole()->id]);
$client->portalUsers()->attach($relative->id, ['relation' => 'next_of_kin']);

$family = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true, 'title' => 'Aroha — care team']);
$family->participants()->create(['user_id' => $relative->id, 'role' => 'family']);
$family->messages()->create(['sender_id' => $relative->id, 'sender_type' => 'family', 'client_id' => $client->id, 'content' => 'Kia ora, is Aroha still going to the swimming session on Friday?', 'message_type' => 'text']);
$direct = OpsConversation::create(['conversation_type' => 'direct']);
foreach ([$lead, $worker] as $person) {
    $direct->participants()->create(['user_id' => $person->id, 'role' => 'member']);
}
$direct->messages()->create(['sender_id' => $lead->id, 'sender_type' => 'staff', 'content' => 'Can you cover the Friday outing handover?', 'message_type' => 'text']);

$process = new Process([PHP_BINARY, '-S', '127.0.0.1:8767', '-t', $root.'/public', __DIR__.'/router.php'], $root, [...$_ENV, 'CHAT_PREVIEW_DATABASE' => $database, 'CHAT_PREVIEW_CALLING' => getenv('CHAT_PREVIEW_CALLING') ?: '0']);
$process->setTimeout(null);
$process->start();
register_shutdown_function(fn () => $process->stop());
echo json_encode(['url' => 'http://127.0.0.1:8767', 'database' => $database, 'people' => ['lead@chat-preview.test', 'worker@chat-preview.test', 'family@chat-preview.test'], 'client_id' => $client->id]).PHP_EOL;
while ($process->isRunning()) {
    echo $process->getIncrementalErrorOutput();
    usleep(200000);
}
