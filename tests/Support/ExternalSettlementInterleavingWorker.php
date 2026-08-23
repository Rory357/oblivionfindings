<?php

declare(strict_types=1);

use App\Domain\Finance\Models\FinExternalSettlement;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Services\ExternalSettlementService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $action, $runId, $actorId, $readyPath, $acquiredPath, $releasePath, $artifactRoot] = $argv;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=mysql');
putenv("DB_DATABASE={$database}");
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.local.root' => $artifactRoot]);
Storage::forgetDisk('local');

try {
    $actor = User::query()->findOrFail((int) $actorId);
    $settlements = $app->make(ExternalSettlementService::class);
    $baseAction = str_ends_with($action, '_hold') ? substr($action, 0, -5) : $action;

    $result = DB::transaction(function () use (
        $action,
        $baseAction,
        $runId,
        $actor,
        $settlements,
        $app,
        $readyPath,
        $acquiredPath,
        $releasePath,
    ) {
        if ($baseAction === 'settle') {
            $locator = $settlements->requiredSettlement(
                FinPaymentRun::query()->findOrFail((int) $runId),
                ExternalSettlementService::PAYMENT_RUN,
                $actor,
            );
            $app->make(JournalPostingService::class)->lockJournalSequence((int) $locator->organization_id);
        }

        if (! str_ends_with($action, '_hold')) {
            // This marker is emitted immediately before the lock that must
            // block behind the holder. The harness requires acquiredPath to
            // remain absent until it releases the holder.
            file_put_contents($readyPath, (string) getmypid(), LOCK_EX);
        }

        $run = FinPaymentRun::query()->lockForUpdate()->findOrFail((int) $runId);
        FinExternalSettlement::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->id)
            ->where('purpose', ExternalSettlementService::PAYMENT_RUN)
            ->lockForUpdate()
            ->firstOrFail();
        file_put_contents($acquiredPath, (string) getmypid(), LOCK_EX);

        if (str_ends_with($action, '_hold')) {
            file_put_contents($readyPath, (string) getmypid(), LOCK_EX);
            $deadline = microtime(true) + 20;
            while (! is_file($releasePath)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the external-settlement release barrier.');
                }
                usleep(20_000);
            }
        }

        return match ($baseAction) {
            'export' => $settlements->markExported(
                $run,
                ExternalSettlementService::PAYMENT_RUN,
                $actor,
            ),
            'accept' => $settlements->accept(
                $run,
                ExternalSettlementService::PAYMENT_RUN,
                $actor,
                'mysql-concurrent-accept',
                'BANK-MYSQL-ACCEPTED',
                ['digest' => hash('sha256', 'mysql-concurrent-accept')],
            ),
            'settle' => $settlements->settlePaymentRun($run, $actor, 'mysql-concurrent-settle'),
            default => throw new RuntimeException("Unknown external-settlement action {$action}."),
        };
    });

    echo json_encode([
        'action' => $action,
        'status' => $result->status,
        'journal_id' => $result->journal_id ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
