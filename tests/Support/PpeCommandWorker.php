<?php

declare(strict_types=1);

use App\Models\PpeInventory;
use App\Models\User;
use App\Services\HealthSafety\PpeCommandService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (count($argv) !== 8) { throw new RuntimeException('Exact PPE worker arguments are required.'); }
[$script, $database, $actorId, $itemId, $operation, $encoded, $readyPath, $goPath] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database || ! str_contains(strtolower($database), 'test')
    || ! ctype_digit($actorId) || ! ctype_digit($itemId) || ! in_array($operation, ['update', 'allocate'], true)
    || ! str_starts_with($readyPath, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-ppe-ready-')
    || ! str_starts_with($goPath, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-ppe-go-')) {
    throw new RuntimeException('PPE workers require the admitted test schema and bounded barrier paths.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql'
    || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $database) { throw new RuntimeException('PPE worker database mismatch.'); }
Carbon::setTestNow(Carbon::parse('2026-10-12T01:00:00Z'));
file_put_contents($readyPath, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 45;
while (! is_file($goPath)) { if (microtime(true) >= $deadline) { throw new RuntimeException('PPE worker barrier timed out.'); } usleep(10000); }
try {
    $actor = User::findOrFail((int) $actorId); $item = PpeInventory::findOrFail((int) $itemId);
    $input = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
    try {
        $service = $app->make(PpeCommandService::class);
        $receipt = $operation === 'allocate' ? $service->allocate($item, $actor, $input) : $service->updateInventory($item, $actor, $input);
        echo json_encode(['status' => 'accepted', 'receipt' => $receipt], JSON_THROW_ON_ERROR);
    } catch (ValidationException $error) {
        echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $failure) { fwrite(STDERR, $failure::class.': PPE command failed'.PHP_EOL); exit(1); }
