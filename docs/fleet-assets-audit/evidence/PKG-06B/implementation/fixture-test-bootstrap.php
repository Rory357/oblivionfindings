<?php

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

// Optional local regression bootstrap. Reuses only the explicitly owned synthetic
// browser fixture schema. RefreshDatabase wraps each test in a rollback transaction.
// The fixture's owning process retains schema cleanup responsibility.
$root = dirname(__DIR__, 5);
chdir($root);
if (is_file('.env') || is_file('.env.testing') || is_file('bootstrap/cache/config.php')) {
    throw new RuntimeException('Unexpected environment file');
}
$fixture = json_decode(file_get_contents('storage/framework/pkg06b-browser.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $fixture['database']) || $fixture['database'] !== 'oblivion_findings_pkg06b_8821_test_'.$fixture['pid']) {
    throw new RuntimeException('Unexpected fixture database');
}
require_once $root.'/vendor/autoload.php';
$xml = simplexml_load_file('phpunit.pkg06b.xml');
$env = [];
foreach ($xml->php->env as $row) {
    $env[(string) $row['name']] = (string) $row['value'];
}
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $env['DB_DATABASE']) {
    throw new RuntimeException('Use phpunit.pkg06b.xml');
}
$pdo = new PDO('mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'].';dbname='.$fixture['database'], $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$query = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND email = ?');
$query->execute([$fixture['user'], 'pkg06b-verification@example.invalid']);
if ((int) $query->fetchColumn() !== 1) {
    throw new RuntimeException('Fixture identity check failed');
}
foreach (['testDatabaseBaseName' => $env['DB_DATABASE'], 'isolatedMysqlDatabase' => $fixture['database'], 'isolatedMysqlPrepared' => true, 'isolatedMysqlSchemaLoaded' => true, 'pendingMigrationsApplied' => true] as $name => $value) {
    (new ReflectionProperty(TestCase::class, $name))->setValue(null, $value);
}
RefreshDatabaseState::$migrated = true;
