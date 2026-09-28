<?php
// Local synthetic fixture only. Never copy credentials or records from the operating application.
$root = dirname(__DIR__, 4);
chdir($root);
require $root.'/vendor/autoload.php';
$fixtureUsername = getenv('PKG08_DB_USERNAME');
$fixturePassword = getenv('PKG08_DB_PASSWORD');
if ($fixtureUsername === false || $fixturePassword === false) {
    throw new RuntimeException('Set PKG08_DB_USERNAME and PKG08_DB_PASSWORD for the disposable local test database.');
}
$environment = [
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_URL' => 'http://127.0.0.1:8794', 'APP_DEBUG' => 'true',
    'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'of_pkg08_browser_20260928', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_USERNAME' => $fixtureUsername, 'DB_PASSWORD' => $fixturePassword, 'DB_EMULATE_PREPARES' => 'true',
    'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file',
    'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
];
foreach ($environment as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
$harness = new class('fixture') extends \Tests\TestCase {
    protected function registerIsolatedDatabaseCleanup(string $host, string $port, string $username, string $password, string $database): void {}
    public function prepare() { return $this->createApplication(); }
};
$app = $harness->prepare();
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Http::preventStrayRequests();
$actor = \App\Models\User::factory()->withoutTwoFactor()->create(['name' => 'Settings test coordinator', 'email' => 'pkg08.coordinator@example.test', 'password' => 'Pkg08-browser-fixture-only!', 'role' => 'support_worker', 'approved_at' => now(), 'email_verified_at' => now()]);
foreach (['fleet.viewAny', 'assets.viewAny', 'fleet.settings.manage', 'assets.create', 'settings.access.manage', 'sites.viewAny'] as $key) {
    $permission = \App\Models\Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
    $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
}
$site = \App\Models\Site::factory()->create(['name' => 'Settings test site', 'is_active' => true, 'archived' => false]);
\App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
$environment['DB_DATABASE'] = config('database.connections.mysql.database');
if (! str_starts_with($environment['DB_DATABASE'], 'of_pkg08_browser_20260928_')) throw new RuntimeException('Refusing unexpected browser database.');
file_put_contents(__DIR__.'/browser-environment.json', json_encode($environment, JSON_PRETTY_PRINT));
file_put_contents(__DIR__.'/browser-fixture.json', json_encode(['database' => $environment['DB_DATABASE'], 'actor_id' => $actor->id, 'site_id' => $site->id, 'source' => $root], JSON_PRETTY_PRINT));
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('wayfinder:generate', ['--with-form' => true]);
echo json_encode(['database' => $environment['DB_DATABASE'], 'actor_id' => $actor->id, 'ready' => true]);
