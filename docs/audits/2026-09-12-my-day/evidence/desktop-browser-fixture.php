<?php

// Local verification only. Owns a disposable schema and loopback server;
// never changes the application's configured database or real users.
require dirname(__DIR__, 4).'/vendor/autoload.php';
chdir(dirname(__DIR__, 4));

foreach (simplexml_load_file('phpunit.xml')->php->env as $entry) {
    $name = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$token = 'md_ui_01a094e0';
foreach ([
    'APP_ENV' => 'testing', 'DB_DATABASE' => 'oblivion_findings_codex_test_myday_browser', 'TEST_TOKEN' => $token,
    'APP_URL' => 'http://127.0.0.1:8766', 'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => 'false',
    'SESSION_DOMAIN' => '', 'SESSION_COOKIE' => $token, 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

$harness = new class('desktopFixture') extends Tests\TestCase {};
$app = $harness->createApplication();
$database = config('database.connections.mysql.database');
if (! str_starts_with($database, 'oblivion_findings_codex_test_myday_browser_'.$token)) {
    throw new RuntimeException('Unexpected browser fixture database.');
}

$worker = App\Models\User::factory()->frontlineWorker()->withoutTwoFactor()->create([
    'name' => 'Taylor Demo', 'email' => 'myday-worker@demo.test', 'password' => Illuminate\Support\Facades\Hash::make('MyDay-demo-482!'),
]);
$site = App\Models\Site::factory()->create(['name' => 'Rimu House · Demo', 'is_active' => true, 'archived' => false]);
App\Domain\Hr\Models\HrEmployeeProfile::factory()->create([
    'user_id' => $worker->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
    'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null,
]);
foreach (['shifts.viewAssigned', 'shifts.tasks.createSelf', 'shifts.tasks.updateSelf', 'clients.viewAssigned', 'timesheets.create', 'clinical.observations.record', 'handovers.create'] as $key) {
    $permission = App\Models\Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'shifts', 'module' => 'Operations']);
    $worker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
}
$clients = [];
foreach ([['Mere', 'Demo'], ['James', 'Demo'], ['Private', 'Demo']] as $index => [$first, $last]) {
    $client = App\Models\Client::factory()->create(['first_name' => $first, 'last_name' => $last, 'site_id' => $site->id, 'status' => 'active']);
    if ($index < 2) $client->supportWorkers()->attach($worker->id);
    $clients[] = $client;
}
$shift = App\Models\Shift::factory()->create([
    'user_id' => $worker->id, 'client_id' => $clients[0]->id, 'site_id' => $site->id,
    'status' => 'in_progress', 'starts_at' => now()->subHours(2)->startOfMinute(), 'ends_at' => now()->addHours(5)->startOfMinute(),
    'actual_starts_at' => now()->subHours(2),
]);
$shift->forceFill(['published_at' => now()])->save();
App\Domain\Hr\Models\HrAttendanceSession::create([
    'user_id' => $worker->id, 'shift_id' => $shift->id, 'site_id' => $site->id,
    'clock_in_at' => now()->subHours(2), 'status' => 'open', 'source' => 'web', 'created_by' => $worker->id,
]);
foreach ([
    ['label' => 'Prepare the activity bag', 'client_id' => $clients[0]->id, 'scheduled_at' => now()->subMinutes(5)],
    ['label' => 'Support James with his afternoon walk', 'client_id' => $clients[1]->id, 'scheduled_at' => now()->addHour()],
    ['label' => 'Check the shared supplies cupboard', 'client_id' => null, 'scheduled_at' => null],
] as $index => $task) {
    $shift->tasks()->create([...$task, 'task_scope' => $task['client_id'] ? 'client' : 'site', 'created_by' => $worker->id, 'sort_order' => $index]);
}

$process = new Symfony\Component\Process\Process([
    PHP_BINARY, '-S', '127.0.0.1:8766', '-t', public_path(), __DIR__.'/desktop-browser-router.php',
], base_path(), array_merge($_ENV, [
    'DB_DATABASE' => $database, 'DB_USERNAME' => config('database.connections.mysql.username'),
    'DB_PASSWORD' => config('database.connections.mysql.password'),
    'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
]));
$process->setTimeout(null);
$process->start();
register_shutdown_function(fn () => $process->stop());
$identity = ['url' => 'http://127.0.0.1:8766/my-day', 'database' => $database, 'worker_id' => $worker->id, 'shift_id' => $shift->id, 'allowed_client_ids' => [$clients[0]->id, $clients[1]->id], 'private_client_id' => $clients[2]->id];
file_put_contents(__DIR__.'/desktop-browser-identity.json', json_encode($identity, JSON_PRETTY_PRINT));
echo json_encode($identity).PHP_EOL;
echo "Synthetic login: myday-worker@demo.test / MyDay-demo-482!\n";
$stop = __DIR__.'/desktop-browser.stop';
while ($process->isRunning() && ! file_exists($stop)) {
    $process->getIncrementalOutput();
    $process->getIncrementalErrorOutput();
    usleep(250000);
}
$process->stop();
if (file_exists($stop)) unlink($stop);
echo "Browser fixture server stopped. Disposable database cleanup runs at shutdown.\n";
