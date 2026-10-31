<?php

declare(strict_types=1);

use App\Models\HazardousSubstance;
use App\Models\User;
use App\Services\HealthSafety\ChemicalCommandService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (count($argv) !== 8) { throw new RuntimeException('Exact chemical test worker arguments are required.'); }
[$script, $database, $actorId, $parentId, $operation, $encoded, $ready, $go] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_contains(strtolower($database), 'test')
    || ! ctype_digit($actorId) || (int) $actorId < 1 || ! ctype_digit($parentId) || (int) $parentId < 1
    || ! in_array($operation, ['update', 'storage'], true)) { throw new RuntimeException('Chemical workers require the admitted test schema and positive identities.'); }
foreach ([$ready, $go] as $path) {
    if (! str_starts_with($path, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-chemical-race-') || dirname($path) !== sys_get_temp_dir()) {
        throw new RuntimeException('Chemical race barriers must stay inside the test temporary directory.');
    }
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || \Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql'
    || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $database
    || \Illuminate\Support\Facades\DB::transactionLevel() !== 0) { throw new RuntimeException('Chemical worker physical test database mismatch.'); }
Carbon::setTestNow(Carbon::parse('2026-10-12T01:00:00Z'));
if (file_put_contents($ready, (string) getmypid(), LOCK_EX) === false) { throw new RuntimeException('Chemical ready barrier was not written.'); }
$deadline = microtime(true) + 45;
while (! is_file($go)) { if (microtime(true) >= $deadline) { throw new RuntimeException('Chemical race barrier timed out.'); } usleep(10000); }
try {
    $input = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
    $actor = User::findOrFail((int) $actorId); $parent = HazardousSubstance::findOrFail((int) $parentId);
    for ($attempt = 0; $attempt < 16; $attempt++) {
        try {
            $service = $app->make(ChemicalCommandService::class);
            $r = $operation === 'storage' ? $service->createStorage($parent, $actor, $input) : $service->update($parent, $actor, $input);
            echo json_encode(['status' => 'accepted', 'receipt' => $r], JSON_THROW_ON_ERROR); exit(0);
        } catch (ValidationException $error) {
            echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR); exit(0);
        } catch (QueryException $error) {
            // Simulate the client's exact retry of a transient refused lock, never classify it as acceptance.
            if (! in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true) || $attempt === 15) { throw $error; }
            usleep(50000);
        }
    }
} catch (Throwable $error) { fwrite(STDERR, $error::class.': Chemical race command failed'.PHP_EOL); exit(1); }
