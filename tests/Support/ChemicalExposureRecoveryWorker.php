<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureAssociationRecoveryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (count($argv) !== 8) { throw new RuntimeException('Exact recovery worker arguments required.'); }
[$script, $database, $actorId, $exposureId, $encoded, $ready, $go, $mode] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_starts_with($database, 'oblivion_hs_')
    || ! str_contains(strtolower($database), 'test') || ! ctype_digit($actorId) || (int) $actorId < 1
    || ! ctype_digit($exposureId) || (int) $exposureId < 1 || ! in_array($mode, ['start', 'claim'], true)) {
    throw new RuntimeException('Recovery worker requires the exact dedicated H&S testing schema and positive identities.');
}
foreach ([$ready, $go] as $path) {
    if (dirname($path) !== sys_get_temp_dir() || ! str_starts_with(basename($path), 'hs-chemical-recovery-race-')) {
        throw new RuntimeException('Recovery barriers must remain in the designated test temporary directory.');
    }
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
// As in ChemicalExposureCommandTestCase: workers exercise the commands, not the automatic delivery after the save.
config(['health_safety.exposure_automatic_delivery' => false]);
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database
    || DB::transactionLevel() !== 0 || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
    || (int) config('database.connections.mysql.port') !== 3306 || config('database.connections.mysql.url')
    || config('database.connections.mysql.unix_socket')) { throw new RuntimeException('Recovery worker physical schema/connection mismatch.'); }
Carbon::setTestNow(Carbon::parse('2026-10-12T01:00:00Z')->utc());
$reached = false;
$barrier = function () use (&$reached, $ready, $go) {
    if ($reached) { return; } $reached = true;
    if (file_put_contents($ready, 'ready', LOCK_EX) === false) { throw new RuntimeException('Recovery barrier persistence failed.'); }
    $until = microtime(true) + 45;
    while (! is_file($go)) { if (microtime(true) >= $until) { throw new RuntimeException('Recovery barrier timed out.'); } usleep(10000); }
};
if ($mode === 'start') { $barrier(); }
else { DB::connection()->beforeExecuting(function ($sql) use ($barrier) {
    if (preg_match('/^insert ignore into ["`]?chemical_command_intents["`]?\b/i', $sql)) { $barrier(); }
}); }
try {
    $input = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR); $actor = User::findOrFail((int) $actorId);
    $receipt = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try { $receipt = app(ChemicalExposureAssociationRecoveryService::class)->recordReview($actor, (int) $exposureId, $input); break; }
        catch (QueryException $busy) {
            if (! in_array((int) ($busy->errorInfo[1] ?? 0), [1205, 1213, 3572], true) || $attempt === 99) { throw $busy; }
            usleep(20000);
        }
    }
    echo json_encode(['status' => 'accepted', 'receipt' => $receipt], JSON_THROW_ON_ERROR);
} catch (ValidationException $error) {
    echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR);
} catch (Throwable) { echo json_encode(['status' => 'failure'], JSON_THROW_ON_ERROR); exit(2); }
