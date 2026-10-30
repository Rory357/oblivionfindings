<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Only the existing owner-created synthetic preview may adopt published Maps.
chdir(dirname(__DIR__, 5));
if (PHP_SAPI !== 'cli' || is_file('.env') || is_file('.env.testing') || is_file('bootstrap/cache/config.php')) {
    throw new RuntimeException('Unexpected environment');
}
$fixture = json_decode(file_get_contents('storage/framework/pkg06b-browser.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $fixture['database']) || $fixture['database'] !== 'oblivion_findings_pkg06b_8821_test_'.$fixture['pid']) {
    throw new RuntimeException('Unexpected database');
}
$xml = simplexml_load_file('phpunit.pkg06b.xml');
foreach ($xml->php->env as $entry) {
    $name = (string) $entry['name'];
    $value = (string) $entry['value'];
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
putenv('DB_DATABASE='.$fixture['database']);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $fixture['database'];
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
\App\Models\User::whereKey($fixture['user'])->where('email', 'pkg06b-verification@example.invalid')->firstOrFail();
$before = DB::table('asset_label_batches')->orderBy('id')->get()->toJson();
$migrations = ['2026_09_27_000100_version_shared_boundaries', '2026_09_27_000200_retain_client_boundary_provenance', '2026_09_27_000300_retain_boundary_event_and_rule_provenance'];
foreach ($migrations as $migration) {
    if (Artisan::call('migrate', ['--path' => 'database/migrations/'.$migration.'.php', '--force' => true]) !== 0) {
        throw new RuntimeException('Synthetic fixture migration failed: '.$migration);
    }
}
$after = DB::table('asset_label_batches')->orderBy('id')->get()->toJson();
if ($before !== $after) {
    throw new RuntimeException('Label history changed');
}
echo json_encode(['database' => $fixture['database'], 'migrations' => DB::table('migrations')->whereIn('migration', $migrations)->get(['migration', 'batch']),
    'labelHistoryUnchanged' => true, 'labelHistorySha256' => hash('sha256', $after), 'operationalDatabaseUsed' => false], JSON_PRETTY_PRINT).PHP_EOL;
