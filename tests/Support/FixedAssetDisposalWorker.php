<?php

declare(strict_types=1);

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Services\FixedAssetService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $assetId, $readyPath, $releasePath] = $argv;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv("DB_DATABASE={$database}");
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents($readyPath, (string) getmypid(), LOCK_EX);

$deadline = microtime(true) + 20;
while (! is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the fixed-asset disposal start barrier.\n");
        exit(1);
    }
    usleep(20_000);
}

try {
    $asset = FinFixedAsset::query()->findOrFail((int) $assetId);
    $result = $app->make(FixedAssetService::class)->disposeAsset($asset, [
        'disposed_date' => '2026-08-20',
        'disposal_proceeds' => '500.00',
    ]);
    $disposal = $result->disposal()->firstOrFail();

    echo json_encode([
        'asset_id' => (int) $result->id,
        'disposal_id' => (int) $disposal->id,
        'journal_id' => $disposal->journal_id === null ? null : (int) $disposal->journal_id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
