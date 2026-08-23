<?php

declare(strict_types=1);

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FixedAssetService;
use App\Domain\Finance\Services\JournalPostingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $action, $assetId, $journalId, $readyPath, $acquiredPath, $releasePath] = $argv;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv("DB_DATABASE={$database}");
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents($readyPath, (string) getmypid(), LOCK_EX);

$waitForRelease = static function () use ($releasePath): void {
    $deadline = microtime(true) + 20;
    while (! is_file($releasePath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the fixed-asset interleaving release barrier.');
        }

        usleep(20_000);
    }
};

$holdSequence = static function (callable $operation) use ($app, $acquiredPath, $waitForRelease): mixed {
    return DB::transaction(function () use ($app, $acquiredPath, $waitForRelease, $operation): mixed {
        $app->make(JournalPostingService::class)->lockJournalSequence(1);
        file_put_contents($acquiredPath, (string) getmypid(), LOCK_EX);
        $waitForRelease();

        return $operation();
    });
};

try {
    $asset = static fn (): FinFixedAsset => FinFixedAsset::query()->findOrFail((int) $assetId);
    $assets = $app->make(FixedAssetService::class);
    $journals = $app->make(JournalPostingService::class);

    $result = match ($action) {
        'hold-capitalise' => $holdSequence(fn () => $assets->capitaliseAsset($asset())),
        'hold-dispose' => $holdSequence(fn () => $assets->disposeAsset($asset(), [
            'disposed_date' => '2026-08-20',
            'disposal_proceeds' => '1200.00',
        ])),
        'hold-depreciate' => $holdSequence(fn () => $assets->runDepreciation(1, '2026-08-20')),
        'hold-sequence-journal' => $holdSequence(fn () => FinJournal::query()
            ->whereKey((int) $journalId)
            ->lockForUpdate()
            ->firstOrFail()),
        'capitalise' => $assets->capitaliseAsset($asset()),
        'dispose' => $assets->disposeAsset($asset(), [
            'disposed_date' => '2026-08-20',
            'disposal_proceeds' => '1100.00',
        ]),
        'depreciate' => $assets->runDepreciation(1, '2026-08-20'),
        'reverse-journal' => $journals->reverse(
            FinJournal::query()->findOrFail((int) $journalId),
            'Forced sequence-before-journal interleaving',
        ),
        default => throw new InvalidArgumentException("Unknown fixed-asset interleaving action: {$action}"),
    };

    echo json_encode([
        'action' => $action,
        'result_id' => is_object($result) ? $result->getKey() : null,
        'result_count' => is_array($result) ? count($result) : null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
