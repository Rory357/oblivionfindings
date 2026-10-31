<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\WorkplaceInjurySourceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (count($argv) !== 7) { throw new RuntimeException('Exact injury worker arguments are required.'); }
[$script, $database, $actorId, $injuryId, $encoded, $readyPath, $goPath] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database
    || ! str_contains(strtolower($database), 'test') || ! ctype_digit($actorId) || ! ctype_digit($injuryId)
    || ! str_starts_with($readyPath, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-injury-ready-')
    || ! str_starts_with($goPath, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-injury-go-')) {
    throw new RuntimeException('Injury workers require the admitted test schema and bounded barrier paths.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
file_put_contents($readyPath, (string) getmypid(), LOCK_EX);
$deadline = microtime(true) + 45;
while (! is_file($goPath)) {
    if (microtime(true) >= $deadline) { throw new RuntimeException('Injury worker barrier timed out.'); }
    usleep(10000);
}
try {
    $actor = User::findOrFail((int) $actorId);
    $input = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
    try {
        $receipt = $app->make(WorkplaceInjurySourceService::class)->update(WorkplaceInjury::findOrFail((int) $injuryId), $actor, $input);
        echo json_encode(['status' => 'accepted', 'receipt' => $receipt], JSON_THROW_ON_ERROR);
    } catch (ValidationException $error) {
        echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': source command failed'.PHP_EOL);
    exit(1);
}
