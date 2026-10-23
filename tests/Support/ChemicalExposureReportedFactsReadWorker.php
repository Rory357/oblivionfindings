<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureSourceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Only the admitted native two-reader suite may execute this physical-source proof worker. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (count($argv) !== 6) { throw new RuntimeException('Exact reported-fact reader worker arguments are required.'); }
[$script, $database, $actorId, $sourceId, $ready, $go] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_contains(strtolower($database), 'test')
    || ! ctype_digit($actorId) || (int) $actorId < 1 || ! ctype_digit($sourceId) || (int) $sourceId < 1) {
    throw new RuntimeException('Reported-fact readers require the admitted test schema and exact positive identities.');
}
foreach ([$ready, $go] as $path) {
    if (! str_starts_with($path, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-reported-fact-readers-') || dirname($path) !== sys_get_temp_dir()) {
        throw new RuntimeException('Reported-fact reader barriers must remain in the exact test temporary directory.');
    }
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== $database || DB::transactionLevel() !== 0) {
    throw new RuntimeException('Reported-fact reader physical schema mismatch.');
}
Carbon::setTestNow(Carbon::parse('2026-10-12T01:00:00Z'));
$reached = false; $sourceSql = [];
DB::listen(function ($event) use (&$reached, &$sourceSql, $ready, $go): void {
    if (! preg_match('/\bfrom\s+["`]?substance_exposure_records["`]?\b/i', $event->sql)) { return; }
    $sourceSql[] = $event->sql;
    if (preg_match('/\bfor\s+update\b/i', $event->sql)) { throw new RuntimeException('Read-only projection acquired a command update lock.'); }
    if ($reached) { return; }
    $reached = true;
    // This listener runs AFTER the real first source SELECT: both workers must concurrently hold its shared lock.
    if (DB::transactionLevel() < 1 || ! preg_match('/\bfor\s+share\s+nowait\b/i', $event->sql)
        || file_put_contents($ready, 'held-source-shared-lock', LOCK_EX) === false) {
        throw new RuntimeException('Reported-fact source shared lock was not retained.');
    }
    $deadline = microtime(true) + 45;
    while (! is_file($go)) { if (microtime(true) >= $deadline) { throw new RuntimeException('Reported-fact shared reader barrier timed out.'); } usleep(10000); }
});
try {
    $actor = User::findOrFail((int) $actorId);
    $result = $app->make(ChemicalExposureCommandAccess::class)->withCurrentViewer($actor,
        fn ($current, $reads, $sites) => $app->make(ChemicalExposureSourceService::class)->reportedFactsForSource((int) $sourceId, $current, $reads, $sites));
    if (! $reached || $sourceSql === []) { throw new RuntimeException('Reported-fact reader never acquired the source lock.'); }
    echo json_encode(['status' => 'read', 'attention' => $result['medical_attention_sought'], 'source_selects' => count($sourceSql),
        'source_for_update' => false], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) { fwrite(STDERR, $error::class.': Reported-fact reader failed'.PHP_EOL); exit(1); }
