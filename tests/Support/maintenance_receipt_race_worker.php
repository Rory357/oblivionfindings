<?php

use App\Domain\Monitoring\Services\MaintenanceCommandService;
use App\Domain\Monitoring\Services\MonitoringPolicyAuditWriter;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Synthetic concurrency worker. Never points at an operational database.
$root = dirname(__DIR__, 2);
$fixture = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
if (! str_starts_with($fixture['database'], 'oblivion_findings_codex_test_mreceipt_race_')) {
    throw new RuntimeException('The maintenance race worker requires its exact isolated test database.');
}
foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => __DIR__.'/no-config-cache', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $fixture['database'], 'DB_USERNAME' => 'testUser', 'DB_PASSWORD' => 'test101', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $fixture['database'], 'database.connections.mysql.username' => 'testUser', 'database.connections.mysql.password' => 'test101', 'cache.default' => 'array']);
DB::purge('mysql');
$actor = User::query()->findOrFail($fixture['actor_id']);
Auth::setUser($actor);
file_put_contents($fixture['started'], (string) getmypid());
if (($argv[2] ?? '') === 'first') {
    $app->instance(MonitoringPolicyAuditWriter::class, new class($fixture) extends MonitoringPolicyAuditWriter
    {
        public function __construct(private array $fixture) {}

        public function write(string $action, Model $policy, User $actor, array $safeContext): void
        {
            if ($action === 'monitoring.maintenance.created') {
                file_put_contents($this->fixture['ready'], (string) getmypid());
                $until = microtime(true) + 15;
                while (! is_file($this->fixture['release'])) {
                    if (microtime(true) > $until) {
                        throw new RuntimeException('Synthetic race barrier expired.');
                    }usleep(10000);
                }
            }
            parent::write($action, $policy, $actor, $safeContext);
        }
    });
}
try {
    $reply = $app->make(MaintenanceCommandService::class)->execute($actor, 'create', null, $fixture['input']);
    echo json_encode(['reply' => $reply, 'pid' => getmypid(), 'synthetic' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['error' => get_class($error), 'message' => $error->getMessage(), 'pid' => getmypid(), 'synthetic' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
