<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\HealthSafety\ChemicalCommandService;
use App\Services\HealthSafety\ChemicalExposureSourceService;
use App\Services\HealthSafety\ChemicalExposureEvidenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (count($argv) !== 8) { throw new RuntimeException('Exact private chemical test worker arguments are required.'); }
[$script, $database, $actorId, $sourceId, $operation, $encoded, $ready, $go] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_contains(strtolower($database), 'test')
    || ! ctype_digit($actorId) || (int) $actorId < 1 || ! ctype_digit($sourceId) || (int) $sourceId < 1
    || ! in_array($operation, ['correct', 'capture', 'public_update', 'upload', 'reassign', 'complete_followup'], true)) {
    throw new RuntimeException('Private chemical workers require the admitted test schema and exact positive identities.');
}
foreach ([$ready, $go] as $path) {
    if (! str_starts_with($path, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-private-chemical-race-') || dirname($path) !== sys_get_temp_dir()) {
        throw new RuntimeException('Private chemical barriers must stay inside the test temporary directory.');
    }
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== $database || DB::transactionLevel() !== 0) {
    throw new RuntimeException('Private chemical worker physical test database mismatch.');
}
$storageRoot = getenv('EXPOSURE_TEST_STORAGE_ROOT');
if (! is_string($storageRoot) || ! str_starts_with($storageRoot, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-private-chemical-race-')
    || dirname($storageRoot) !== sys_get_temp_dir() || ! str_ends_with($storageRoot, '-bytes')) {
    throw new RuntimeException('Private race bytes require the exact generated test temporary storage root.');
}
config(['filesystems.disks.private' => ['driver' => 'local', 'root' => $storageRoot, 'throw' => true]]);
Carbon::setTestNow(Carbon::parse('2026-10-12T01:00:00Z'));
$barrierMode = getenv('EXPOSURE_TEST_BARRIER_MODE');
if (! in_array($barrierMode, ['first_claim', 'start'], true)) { throw new RuntimeException('Exact test barrier mode is required.'); }
$barrierReached = $barrierMode === 'start';
if ($barrierReached) {
    if (file_put_contents($ready, 'before-command', LOCK_EX) === false) { throw new RuntimeException('Private command barrier was not retained.'); }
    $deadline = microtime(true) + 45;
    while (! is_file($go)) { if (microtime(true) >= $deadline) { throw new RuntimeException('Private command barrier timed out.'); } usleep(10000); }
}
// Both independent actors have fresh authority and different root locks; neither UUID exists and neither receipt can exist at this barrier.
DB::connection()->beforeExecuting(function ($sql) use (&$barrierReached, $ready, $go): void {
    if ($barrierReached || ! preg_match('/^insert ignore into ["`]?chemical_command_intents["`]?\b/i', $sql)) { return; }
    $barrierReached = true;
    if (file_put_contents($ready, 'before-first-claim', LOCK_EX) === false) { throw new RuntimeException('Private claim barrier was not retained.'); }
    $deadline = microtime(true) + 45;
    while (! is_file($go)) { if (microtime(true) >= $deadline) { throw new RuntimeException('Private claim barrier timed out.'); } usleep(10000); }
});
try {
    $input = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR); $actor = User::findOrFail((int) $actorId);
    $followupId = null;
    if (in_array($operation, ['reassign', 'complete_followup'], true)) {
        $followupId = $input['followup_id'] ?? null;
        if (! is_int($followupId) || $followupId < 1) { throw new RuntimeException('Transfer worker requires the exact positive follow-up identity.'); }
        unset($input['followup_id']);
    }
    for ($attempt = 0; $attempt < 16; $attempt++) {
        try {
            $receipt = match ($operation) {
                'correct' => $app->make(ChemicalExposureSourceService::class)->correct((int) $sourceId, $actor, $input),
                'reassign' => $app->make(ChemicalExposureSourceService::class)->reassignFollowup((int) $sourceId, $followupId, $actor, $input),
                'complete_followup' => $app->make(ChemicalExposureSourceService::class)->completeFollowup((int) $sourceId, $followupId, $actor, $input),
                'capture' => $app->make(ChemicalExposureSourceService::class)->create((int) $sourceId, $actor, $input),
                'public_update' => $app->make(ChemicalCommandService::class)->update((int) $sourceId, $actor, $input),
                'upload' => $app->make(ChemicalExposureEvidenceService::class)->upload((int) $sourceId, $actor,
                    UploadedFile::fake()->createWithContent('provided.pdf', "%PDF-1.4\nPrivate race provided bytes\n%%EOF"), $input),
            };
            echo json_encode(['status' => 'accepted', 'receipt' => $receipt], JSON_THROW_ON_ERROR); exit(0);
        } catch (ValidationException $error) {
            echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR); exit(0);
        } catch (QueryException $error) {
            if (! in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true) || $attempt === 15) { throw $error; }
            usleep(50000);
        }
    }
} catch (Throwable $error) { fwrite(STDERR, $error::class.': Private chemical race failed'.PHP_EOL); exit(1); }
