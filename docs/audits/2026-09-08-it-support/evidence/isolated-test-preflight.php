<?php

use Illuminate\Contracts\Console\Kernel;

// Read-only preflight: never instantiate Tests\TestCase here (its bootstrap
// deliberately creates/drops isolated schemas). No secrets are printed.
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.connections.mysql');
$token = getenv('TEST_TOKEN');
$checks = [
    'environment_testing' => app()->environment('testing'),
    'database_mysql' => config('database.default') === 'mysql',
    'database_loopback' => $connection['host'] === '127.0.0.1' && (string) $connection['port'] === '3306',
    'dedicated_database_base' => $connection['database'] === 'oblivion_it_support_test',
    'database_url_absent' => empty($connection['url']),
    'config_cache_absent' => ! app()->configurationIsCached(),
    'unique_test_token' => is_string($token) && preg_match('/^it_[a-f0-9]{16}$/', $token) === 1,
    'mail_array' => config('mail.default') === 'array' && config('mail.mailers.array.transport') === 'array',
    'queue_sync' => config('queue.default') === 'sync',
    'broadcast_disabled' => in_array(config('broadcasting.default'), [null, 'null'], true),
    'cache_array' => config('cache.default') === 'array',
    'session_array' => config('session.driver') === 'array',
    'maintenance_files_absent' => ! is_file(storage_path('framework/down')) && ! is_file(storage_path('framework/maintenance.php')),
];

if (in_array(false, $checks, true)) {
    echo json_encode(['isolation_checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}

$testDatabase = $connection['database'].'_'.$token;
$pdo = new PDO('mysql:host=127.0.0.1;port=3306', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$statement->execute([$testDatabase]);
$checks['isolated_schema_does_not_exist'] = (int) $statement->fetchColumn() === 0;

echo json_encode([
    'isolation_checks' => $checks,
    'base_path' => base_path(),
    'isolated_database_for_testcase' => $testDatabase,
    'database_mutations_performed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

exit(in_array(false, $checks, true) ? 1 : 0);
