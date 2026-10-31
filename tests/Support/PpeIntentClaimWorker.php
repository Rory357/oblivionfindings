<?php

declare(strict_types=1);

use App\Models\PpeCommandIntentClaim;
use App\Models\PpeInventory;
use App\Models\User;
use App\Services\HealthSafety\PpeCommandService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (count($argv) !== 11) { throw new RuntimeException('Exact PPE first-claim worker arguments are required.'); }
[$script, $database, $actorId, $itemId, $operation, $encoded, $ready, $go, $claimed, $release, $privateRoot] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_contains(strtolower($database), 'test')
    || ! ctype_digit($actorId) || (int) $actorId < 1 || ! ctype_digit($itemId) || (int) $itemId < 1
    || ! in_array($operation, ['update', 'upload'], true)) { throw new RuntimeException('PPE workers require the admitted test schema and positive canonical identities.'); }
foreach ([$ready, $go, $claimed, $release] as $path) {
    if (! str_starts_with($path, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-ppe-intent-') || dirname($path) !== sys_get_temp_dir()) {
        throw new RuntimeException('PPE worker barriers must stay within the bounded test temporary directory.');
    }
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database
    || DB::transactionLevel() !== 0) { throw new RuntimeException('PPE worker physical database mismatch.'); }
$testingRoot = realpath(storage_path('framework/testing')); $actualRoot = realpath($privateRoot);
$normal = fn ($path) => str_replace('\\', '/', $path);
if (! $testingRoot || ! $actualRoot || ! str_starts_with(strtolower($normal($actualRoot)), strtolower(rtrim($normal($testingRoot), '/').'/'))) {
    throw new RuntimeException('PPE worker private files must stay in the admitted testing storage directory.');
}
config(['filesystems.disks.private' => ['driver' => 'local', 'root' => $actualRoot, 'throw' => true]]);
Carbon::setTestNow(Carbon::parse('2026-10-12T01:00:00Z'));
$input = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
$key = $input['idempotency_key'] ?? null;
if (! is_string($key) || ! preg_match('/^[0-9a-f-]{36}$/D', $key)) { throw new RuntimeException('PPE worker requires the exact reviewed UUID.'); }
$wait = function (string $path): void {
    $deadline = microtime(true) + 45;
    while (! is_file($path)) { if (microtime(true) >= $deadline) { throw new RuntimeException('PPE intent barrier timed out.'); } usleep(10000); }
};
PpeCommandIntentClaim::created(function ($claim) use ($key, $claimed, $release, $wait): void {
    if ($claim->idempotency_key === $key) {
        if (file_put_contents($claimed, (string) getmypid(), LOCK_EX) === false) { throw new RuntimeException('PPE claim barrier could not be written.'); }
        $wait($release); // The actual UNIQUE insert is held before ANY receipt or file reservation.
    }
});
if (file_put_contents($ready, (string) getmypid(), LOCK_EX) === false) { throw new RuntimeException('PPE ready barrier could not be written.'); }
$wait($go);
try {
    $actor = User::findOrFail((int) $actorId); $item = PpeInventory::findOrFail((int) $itemId);
    if ($operation === 'upload') {
        $bytes = $input['_worker_bytes'] ?? null; unset($input['_worker_bytes']);
        if (! is_string($bytes) || strlen($bytes) > 4096) { throw new RuntimeException('PPE worker upload fixture is bounded.'); }
        $input['file'] = UploadedFile::fake()->createWithContent('intent.txt', $bytes);
    }
    try {
        $service = $app->make(PpeCommandService::class);
        $receipt = $operation === 'upload' ? $service->uploadAttachment('inventory', $item->id, $actor, $input) : $service->updateInventory($item, $actor, $input);
        echo json_encode(['status' => 'accepted', 'receipt' => $receipt], JSON_THROW_ON_ERROR);
    } catch (ValidationException $error) {
        echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $failure) { fwrite(STDERR, $failure::class.': PPE first claim failed'.PHP_EOL); exit(1); }
