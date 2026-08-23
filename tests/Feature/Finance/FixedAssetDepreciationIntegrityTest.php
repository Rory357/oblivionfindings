<?php

use App\Domain\Finance\Jobs\RunDepreciationJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDepreciation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FixedAssetService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-15 12:00:00');

    $this->actor = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'finance.ledger.manage'],
        ['description' => 'Manage the general ledger'],
    );
    $this->actor->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $this->actingAs($this->actor);

    $this->expenseAccount = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '8000',
        'name' => 'Depreciation expense',
        'type' => 'expense',
        'is_active' => true,
    ]);
    $this->accumulatedAccount = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '1590',
        'name' => 'Accumulated depreciation',
        'type' => 'asset',
        'is_active' => true,
    ]);
    $this->assetAccount = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '1500',
        'name' => 'Fixed assets',
        'type' => 'asset',
        'is_active' => true,
    ]);
    $this->bankAccount = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '1000',
        'name' => 'Operating bank',
        'type' => 'asset',
        'is_active' => true,
    ]);
    $this->gainLossAccount = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '8400',
        'name' => 'Gain or loss on disposal',
        'type' => 'expense',
        'is_active' => true,
    ]);
    $this->period = FinFiscalPeriod::query()->create([
        'organization_id' => 1,
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);
    $this->asset = FinFixedAsset::factory()->create([
        'organization_id' => 1,
        'asset_name' => 'Depreciation test asset',
        'asset_tag' => 'FA-DEP-001',
        'purchase_date' => '2026-01-01',
        'purchase_cost' => '1200.00',
        'residual_value' => '0.00',
        'useful_life_months' => 12,
        'depreciation_method' => 'straight_line',
        'accumulated_depreciation' => '0.00',
        'gl_asset_account_id' => $this->assetAccount->id,
        'gl_expense_account_id' => $this->expenseAccount->id,
        'gl_depreciation_account_id' => $this->accumulatedAccount->id,
        'status' => 'active',
    ]);
    $this->service = app(FixedAssetService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('normalizes same-month requests onto one durable depreciation and journal execution', function (): void {
    $first = $this->service->runDepreciation(1, '2026-08-31');
    $replay = $this->service->runDepreciation(1, '2026-08-02');

    $depreciation = FinFixedAssetDepreciation::query()->sole();
    $journal = FinJournal::query()->sole();

    expect($first)->toHaveCount(1)
        ->and($replay)->toHaveCount(1)
        ->and($first[0]['depreciation_id'])->toBe($depreciation->id)
        ->and($first[0]['replayed'])->toBeFalse()
        ->and($replay[0]['depreciation_id'])->toBe($depreciation->id)
        ->and($replay[0]['journal_id'])->toBe($journal->id)
        ->and($replay[0]['replayed'])->toBeTrue()
        ->and($depreciation->depreciation_date->toDateString())->toBe('2026-08-01')
        ->and((string) $depreciation->amount)->toBe('100.00')
        ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('100.00')
        ->and($journal->source_type)->toBe(FinFixedAssetDepreciation::class)
        ->and($journal->source_id)->toBe($depreciation->id)
        ->and((string) $journal->total_amount)->toBe('100.00');
});

it('keeps different normalized months as distinct executions', function (): void {
    $august = $this->service->runDepreciation(1, '2026-08-31');
    $september = $this->service->runDepreciation(1, '2026-09-15');

    expect($august[0]['period'])->toBe('2026-08')
        ->and($september[0]['period'])->toBe('2026-09')
        ->and($september[0]['replayed'])->toBeFalse()
        ->and(FinFixedAssetDepreciation::query()->count())->toBe(2)
        ->and(FinJournal::query()->where('source_type', FinFixedAssetDepreciation::class)->count())->toBe(2)
        ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('200.00');
});

it('returns the existing period claim after the first run fully depreciates the asset', function (): void {
    $this->asset->update(['useful_life_months' => 1]);

    $first = $this->service->runDepreciation(1, '2026-08-31');
    $replay = $this->service->runDepreciation(1, '2026-08-01');

    expect($first)->toHaveCount(1)
        ->and($replay)->toHaveCount(1)
        ->and($first[0]['depreciation_id'])->toBe($replay[0]['depreciation_id'])
        ->and($replay[0]['replayed'])->toBeTrue()
        ->and($this->asset->fresh()->status)->toBe('fully_depreciated')
        ->and(FinFixedAssetDepreciation::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1);
});

it('establishes the sequence mutex even for a no-GL organisation with no account row', function (): void {
    $asset = FinFixedAsset::factory()->create([
        'organization_id' => 2,
        'asset_name' => 'No GL depreciation asset',
        'asset_tag' => 'FA-NO-GL-001',
        'purchase_date' => '2026-01-01',
        'purchase_cost' => '1200.00',
        'residual_value' => '0.00',
        'useful_life_months' => 12,
        'depreciation_method' => 'straight_line',
        'accumulated_depreciation' => '0.00',
        'gl_asset_account_id' => null,
        'gl_expense_account_id' => null,
        'gl_depreciation_account_id' => null,
        'status' => 'active',
    ]);

    $result = $this->service->runDepreciation(2, '2026-08-20');

    expect($result)->toHaveCount(1)
        ->and($result[0]['asset_id'])->toBe($asset->id)
        ->and($result[0]['journal_id'])->toBeNull()
        ->and((int) DB::table('fin_journal_sequences')->where('organization_id', 2)->value('next_number'))->toBe(1)
        ->and(FinJournal::query()->where('organization_id', 2)->count())->toBe(0);
});

it('rejects an absent organisation before depreciation can inspect another organisation', function (): void {
    expect(fn () => $this->service->runDepreciation(null, '2026-08-20'))
        ->toThrow(InvalidArgumentException::class, 'An organisation is required');

    expect(fn () => $this->service->runDepreciation(0, '2026-08-20'))
        ->toThrow(InvalidArgumentException::class, 'An organisation is required');

    expect(FinFixedAssetDepreciation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('0.00');
});

it('blocks duplicate legacy asset-month rows before applying any migration schema change', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $actorId = $this->actor->id;
    $connection->commit();

    $migration = require database_path('migrations/2026_08_23_000080_enforce_fixed_asset_depreciation_period.php');
    $migration->down();

    $now = now();
    DB::table('fin_fixed_asset_depreciations')->insert([
        [
            'fixed_asset_id' => $this->asset->id,
            'depreciation_date' => '2026-08-01',
            'amount' => '50.00',
            'accumulated_total' => '50.00',
            'book_value_after' => '1150.00',
            'journal_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'fixed_asset_id' => $this->asset->id,
            'depreciation_date' => '2026-08-31',
            'amount' => '50.00',
            'accumulated_total' => '100.00',
            'book_value_after' => '1100.00',
            'journal_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    try {
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'both claim asset');

        expect(Schema::hasTable('fin_journal_sequences'))->toBeFalse()
            ->and(Schema::hasColumn('fin_fixed_asset_depreciations', 'reversal_journal_id'))->toBeFalse()
            ->and(DB::table('fin_fixed_asset_depreciations')->orderBy('id')->pluck('depreciation_date')->all())
            ->toBe(['2026-08-01', '2026-08-31']);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('fin_fixed_asset_depreciations')->delete();
        if (! Schema::hasColumn('fin_fixed_asset_depreciations', 'reversal_journal_id')) {
            $migration->up();
        }
        fadResetCommittedFixtures($connection, $actorId);
    }
});

it('rolls back the claim journal and asset balance when journal posting fails', function (): void {
    $this->period->update(['status' => 'closed']);

    expect(fn () => $this->service->runDepreciation(1, '2026-08-20'))
        ->toThrow(InvalidArgumentException::class, "expected 'open'");

    expect(FinFixedAssetDepreciation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('0.00')
        ->and($this->asset->status)->toBe('active');
});

it('corrects depreciation through one linked reversal without erasing the execution', function (): void {
    $this->service->runDepreciation(1, '2026-08-20');
    $depreciation = FinFixedAssetDepreciation::query()->sole();
    $journal = $depreciation->journal()->firstOrFail();

    $first = $this->post(route('finance.journals.reverse', $journal), [
        'reason' => 'Approved fixed-asset correction',
    ]);
    $depreciation->refresh();
    $journal->refresh();
    $reversal = FinJournal::query()->findOrFail($depreciation->reversal_journal_id);

    $first->assertRedirect(route('finance.journals.show', $reversal));
    $this->post(route('finance.journals.reverse', $journal), [
        'reason' => 'Safe retry',
    ])->assertRedirect(route('finance.journals.show', $reversal));

    expect(FinFixedAssetDepreciation::query()->count())->toBe(1)
        ->and((string) $depreciation->amount)->toBe('100.00')
        ->and($depreciation->journal_id)->toBe($journal->id)
        ->and($depreciation->reversal_journal_id)->toBe($reversal->id)
        ->and($journal->reversed_by_journal_id)->toBe($reversal->id)
        ->and($reversal->reversal_of_journal_id)->toBe($journal->id)
        ->and($reversal->source_type)->toBe(FinFixedAssetDepreciation::class)
        ->and($reversal->source_id)->toBe($depreciation->id)
        ->and(FinJournal::query()->count())->toBe(2)
        ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('0.00')
        ->and($this->asset->status)->toBe('active');
});

it('makes scheduled execution a replay of the same manual asset-month claim', function (): void {
    $manual = $this->service->runDepreciation(1, '2026-08-29');

    (new RunDepreciationJob)->handle($this->service);

    expect($manual[0]['replayed'])->toBeFalse()
        ->and(FinFixedAssetDepreciation::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1)
        ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('100.00');
});

it('surfaces scheduled posting failures so the queue can retry safely', function (): void {
    $this->period->update(['status' => 'closed']);

    expect(fn () => (new RunDepreciationJob)->handle($this->service))
        ->toThrow(RuntimeException::class, 'failed for organisation(s): 1');

    expect(FinFixedAssetDepreciation::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('0.00');
});

it('serializes independent workers onto one asset-month execution on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $database = $connection->getDatabaseName();
    $assetId = $this->asset->id;
    $actorId = $this->actor->id;
    $connection->commit();

    try {
        $results = fadConcurrentDepreciationRound($connection, $database, $assetId);
        usort($results, fn (array $left, array $right): int => (int) $left['replayed'] <=> (int) $right['replayed']);

        expect(array_column($results, 'replayed'))->toBe([false, true])
            ->and(array_unique(array_column($results, 'depreciation_id')))->toHaveCount(1)
            ->and(FinFixedAssetDepreciation::query()->count())->toBe(1)
            ->and(FinJournal::query()->count())->toBe(1)
            ->and((string) $this->asset->fresh()->accumulated_depreciation)->toBe('100.00');
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('fin_fixed_asset_depreciations')->delete();
        DB::table('fin_journal_lines')->delete();
        DB::table('fin_journals')->delete();
        DB::table('fin_fixed_assets')->delete();
        DB::table('fin_fiscal_periods')->delete();
        DB::table('fin_accounts')->delete();
        DB::table('fin_journal_sequences')->delete();
        DB::table('audit_logs')->delete();
        DB::table('permission_user')->where('user_id', $actorId)->delete();
        DB::table('users')->where('id', $actorId)->delete();
        $connection->beginTransaction();
    }
});

it('keeps capitalisation and depreciation on the shared sequence then asset lock order', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $database = $connection->getDatabaseName();
    $assetId = $this->asset->id;
    $actorId = $this->actor->id;
    $connection->commit();

    try {
        fadForcedInterleaving($database, 'hold-capitalise', 'depreciate', $assetId);

        $asset = $this->asset->fresh();
        expect($asset->acquisition_journal_id)->not->toBeNull()
            ->and((string) $asset->accumulated_depreciation)->toBe('100.00')
            ->and(FinFixedAssetDepreciation::query()->count())->toBe(1)
            ->and(FinJournal::query()->count())->toBe(2);
    } finally {
        fadResetCommittedFixtures($connection, $actorId);
    }
});

it('lets disposal win before depreciation without deadlock or a stale asset projection', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $database = $connection->getDatabaseName();
    $assetId = $this->asset->id;
    $actorId = $this->actor->id;
    $connection->commit();

    try {
        fadForcedInterleaving($database, 'hold-dispose', 'depreciate', $assetId);

        $asset = $this->asset->fresh();
        expect($asset->status)->toBe('disposed')
            ->and((string) $asset->accumulated_depreciation)->toBe('0.00')
            ->and(FinFixedAssetDepreciation::query()->count())->toBe(0)
            ->and(FinJournal::query()->count())->toBe(1);
    } finally {
        fadResetCommittedFixtures($connection, $actorId);
    }
});

it('locks the journal sequence before a reversal source journal under forced interleaving', function (): void {
    $source = app(JournalPostingService::class)->createAndPost(1, [
        'journal_date' => '2026-08-20',
        'type' => 'standard',
        'reference' => 'SEQUENCE-ORDER-SOURCE',
        'description' => 'Forced reversal lock-order source',
        'lines' => [
            [
                'account_id' => $this->expenseAccount->id,
                'debit' => '25.00',
                'credit' => '0.00',
            ],
            [
                'account_id' => $this->accumulatedAccount->id,
                'debit' => '0.00',
                'credit' => '25.00',
            ],
        ],
    ]);
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $database = $connection->getDatabaseName();
    $assetId = $this->asset->id;
    $actorId = $this->actor->id;
    $connection->commit();

    try {
        fadForcedInterleaving(
            $database,
            'hold-sequence-journal',
            'reverse-journal',
            $assetId,
            $source->id,
        );

        $source->refresh();
        expect($source->reversed_by_journal_id)->not->toBeNull()
            ->and(FinJournal::query()->where('reversal_of_journal_id', $source->id)->count())->toBe(1)
            ->and(FinJournal::query()->count())->toBe(2);
    } finally {
        fadResetCommittedFixtures($connection, $actorId);
    }
});

/** @return list<array{depreciation_id:int, replayed:bool}> */
function fadConcurrentDepreciationRound(
    ConnectionInterface $connection,
    string $database,
    int $assetId,
): array {
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-release-{$token}";
    $readyPaths = [];
    $attemptPaths = [];
    $processes = [];

    $connection->beginTransaction();
    FinFixedAsset::query()->whereKey($assetId)->lockForUpdate()->firstOrFail();

    try {
        foreach ([0, 1] as $index) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-ready-{$index}-{$token}";
            $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-attempt-{$index}-{$token}";
            $processes[] = fadStartDepreciationWorker(
                $database,
                $readyPaths[$index],
                $attemptPaths[$index],
                $releasePath,
            );
        }

        fadWaitForFiles($readyPaths, 'Concurrent depreciation workers did not become ready.');
        touch($releasePath);
        fadWaitForFiles($attemptPaths, 'Concurrent depreciation workers did not reach the asset lock.');
        usleep(250_000);

        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A depreciation worker exited before lock release.');
            }
        }

        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A depreciation concurrency worker failed.');
            }
            $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

function fadStartDepreciationWorker(
    string $database,
    string $readyPath,
    string $attemptPath,
    string $releasePath,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
file_put_contents($argv[2], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[4])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the depreciation release barrier.');
    }
    usleep(10_000);
}

file_put_contents($argv[3], 'attempting');
$result = $app->make(App\Domain\Finance\Services\FixedAssetService::class)
    ->runDepreciation(1, '2026-08-29');
if (count($result) !== 1) {
    throw new RuntimeException('Depreciation worker did not resolve one canonical execution.');
}
echo json_encode([
    'depreciation_id' => $result[0]['depreciation_id'],
    'replayed' => $result[0]['replayed'],
], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        $readyPath,
        $attemptPath,
        $releasePath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

/** @return list<array{action:string, result_id:int|null, result_count:int|null}> */
function fadForcedInterleaving(
    string $database,
    string $holderAction,
    string $contenderAction,
    int $assetId,
    int $journalId = 0,
): array {
    $token = (string) Str::uuid();
    $holderReady = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-holder-ready-{$token}";
    $holderAcquired = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-holder-acquired-{$token}";
    $contenderReady = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-contender-ready-{$token}";
    $unusedAcquired = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-contender-acquired-{$token}";
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fad-interleaving-release-{$token}";
    $processes = [];

    try {
        $holder = fadStartInterleavingWorker(
            $database,
            $holderAction,
            $assetId,
            $journalId,
            $holderReady,
            $holderAcquired,
            $releasePath,
        );
        $processes[] = $holder;
        fadWaitForFiles([$holderReady, $holderAcquired], 'The sequence-lock holder did not reach its barrier.');

        $contender = fadStartInterleavingWorker(
            $database,
            $contenderAction,
            $assetId,
            $journalId,
            $contenderReady,
            $unusedAcquired,
            $releasePath,
        );
        $processes[] = $contender;
        fadWaitForFiles([$contenderReady], 'The lock-order contender did not become ready.');
        usleep(250_000);

        if (! $holder->isRunning() || ! $contender->isRunning()) {
            throw new RuntimeException('A forced lock-order worker exited before the sequence barrier was released.');
        }

        touch($releasePath);

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A forced lock-order worker failed.');
            }
            $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([$holderReady, $holderAcquired, $contenderReady, $unusedAcquired, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

function fadStartInterleavingWorker(
    string $database,
    string $action,
    int $assetId,
    int $journalId,
    string $readyPath,
    string $acquiredPath,
    string $releasePath,
): Process {
    $process = new Process([
        PHP_BINARY,
        base_path('tests/Support/FixedAssetDepreciationInterleavingWorker.php'),
        $database,
        $action,
        (string) $assetId,
        (string) $journalId,
        $readyPath,
        $acquiredPath,
        $releasePath,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

function fadResetCommittedFixtures(ConnectionInterface $connection, int $actorId): void
{
    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    DB::table('fin_fixed_asset_depreciations')->delete();
    if (Schema::hasTable('fin_fixed_asset_disposals')) {
        DB::table('fin_fixed_asset_disposals')->delete();
    }
    DB::table('fin_journals')->update([
        'reversed_by_journal_id' => null,
        'reversal_of_journal_id' => null,
    ]);
    DB::table('fin_journal_lines')->delete();
    DB::table('fin_journals')->delete();
    DB::table('fin_fixed_assets')->delete();
    DB::table('fin_fiscal_periods')->delete();
    DB::table('fin_accounts')->delete();
    DB::table('fin_journal_sequences')->delete();
    DB::table('audit_logs')->delete();
    DB::table('permission_user')->where('user_id', $actorId)->delete();
    DB::table('users')->where('id', $actorId)->delete();
    $connection->beginTransaction();
}

/** @param list<string> $paths */
function fadWaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
