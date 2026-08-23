<?php

use App\Domain\Finance\Events\JournalPosted;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDisposal;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FixedAssetService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-20 12:00:00');

    $this->actor = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($this->actor);

    $this->bankAccount = fadpAccount(1, '1000', 'Operating bank', 'asset');
    $this->assetAccount = fadpAccount(1, '1500', 'Motor vehicles', 'asset');
    $this->accumulatedAccount = fadpAccount(1, '1590', 'Accumulated depreciation', 'asset');
    $this->gainLossAccount = fadpAccount(1, '8400', 'Gain or loss on disposal', 'expense');

    $this->period = FinFiscalPeriod::query()->create([
        'organization_id' => 1,
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    $this->asset = FinFixedAsset::factory()->create([
        'organization_id' => 1,
        'asset_name' => 'Disposal integrity vehicle',
        'asset_tag' => 'FA-DISP-001',
        'purchase_date' => '2025-01-01',
        'purchase_cost' => '1000.00',
        'residual_value' => '0.00',
        'useful_life_months' => 60,
        'depreciation_method' => 'straight_line',
        'accumulated_depreciation' => '400.00',
        'gl_asset_account_id' => $this->assetAccount->id,
        'gl_depreciation_account_id' => $this->accumulatedAccount->id,
        'status' => 'active',
    ]);

    $this->service = app(FixedAssetService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('converges identical sequential disposal requests on one typed occurrence and journal', function (): void {
    $payload = fadpPayload();

    $first = $this->service->disposeAsset($this->asset, $payload);
    $replay = $this->service->disposeAsset($this->asset, $payload);
    $disposal = FinFixedAssetDisposal::query()->sole();
    $journal = FinJournal::query()->sole();

    expect($first->id)->toBe($replay->id)
        ->and($replay->status)->toBe('disposed')
        ->and($replay->disposed_date->toDateString())->toBe('2026-08-20')
        ->and((string) $replay->disposal_proceeds)->toBe('500.00')
        ->and($disposal->fixed_asset_id)->toBe($this->asset->id)
        ->and($disposal->occurrence_type)->toBe(FinFixedAssetDisposal::OCCURRENCE_TYPE)
        ->and($disposal->posting_mode)->toBe(FinFixedAssetDisposal::POSTING_MODE_JOURNAL)
        ->and((string) $disposal->book_value)->toBe('600.00')
        ->and((string) $disposal->gain_loss)->toBe('-100.00')
        ->and($disposal->journal_digest)->toBeString()->toHaveLength(64)
        ->and($disposal->journal_id)->toBe($journal->id)
        ->and($journal->source_type)->toBe(FinFixedAssetDisposal::class)
        ->and($journal->source_id)->toBe($disposal->id)
        ->and(FinFixedAssetDisposal::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1);
});

it('replays a valid gain disposal against its complete journal projection', function (): void {
    $payload = fadpPayload('800.00');

    $first = $this->service->disposeAsset($this->asset, $payload);
    $replay = $this->service->disposeAsset($this->asset, $payload);
    $disposal = FinFixedAssetDisposal::query()->sole();
    $journal = FinJournal::query()->sole();

    expect($first->id)->toBe($replay->id)
        ->and((string) $disposal->gain_loss)->toBe('200.00')
        ->and((string) $journal->total_amount)->toBe('1200.00')
        ->and(FinFixedAssetDisposal::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1);
});

it('rejects a changed disposal replay without changing the original occurrence', function (): void {
    $this->service->disposeAsset($this->asset, fadpPayload());
    $disposal = FinFixedAssetDisposal::query()->sole();
    $journal = FinJournal::query()->sole();

    expect(fn () => $this->service->disposeAsset($this->asset, [
        'disposed_date' => '2026-08-21',
        'disposal_proceeds' => '501.00',
    ]))->toThrow(
        InvalidArgumentException::class,
        'already been disposed with different details',
    );

    expect(FinFixedAssetDisposal::query()->count())->toBe(1)
        ->and(FinFixedAssetDisposal::query()->sole()->id)->toBe($disposal->id)
        ->and(FinJournal::query()->count())->toBe(1)
        ->and(FinJournal::query()->sole()->id)->toBe($journal->id)
        ->and($this->asset->fresh()->disposed_date->toDateString())->toBe('2026-08-20')
        ->and((string) $this->asset->fresh()->disposal_proceeds)->toBe('500.00');
});

it('rejects replay when a posted disposal journal line was altered', function (): void {
    $this->service->disposeAsset($this->asset, fadpPayload());
    $disposal = FinFixedAssetDisposal::query()->sole();
    $journal = FinJournal::query()->sole();
    $lossLine = $journal->lines()->where('description', 'like', 'Loss on disposal:%')->firstOrFail();
    $lossLine->update(['debit' => '99.00']);

    expect(fn () => $this->service->disposeAsset($this->asset, fadpPayload()))
        ->toThrow(RuntimeException::class, 'journal has conflicting lineage');

    expect(FinFixedAssetDisposal::query()->sole()->id)->toBe($disposal->id)
        ->and(FinJournal::query()->count())->toBe(1)
        ->and((string) $lossLine->fresh()->debit)->toBe('99.00');
});

it('rejects replay when another journal claims the disposal source tuple', function (): void {
    $this->service->disposeAsset($this->asset, fadpPayload());
    $disposal = FinFixedAssetDisposal::query()->sole();

    app(JournalPostingService::class)->createAndPost(1, [
        'journal_date' => '2026-08-20',
        'type' => 'standard',
        'reference' => 'FORGED-DISPOSAL-SOURCE',
        'description' => 'Second journal claiming one disposal occurrence',
        'source_type' => FinFixedAssetDisposal::class,
        'source_id' => $disposal->id,
        'lines' => [
            ['account_id' => $this->bankAccount->id, 'debit' => '1.00', 'credit' => 0],
            ['account_id' => $this->assetAccount->id, 'debit' => 0, 'credit' => '1.00'],
        ],
    ]);

    expect(fn () => $this->service->disposeAsset($this->asset, fadpPayload()))
        ->toThrow(RuntimeException::class, 'journal has conflicting lineage');
    expect(FinFixedAssetDisposal::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(2);
});

it('keeps a terminal disposal snapshot immutable through asset services', function (): void {
    $this->service->disposeAsset($this->asset, fadpPayload());

    expect(fn () => $this->service->updateAsset($this->asset, [
        'purchase_cost' => '900.00',
    ]))->toThrow(InvalidArgumentException::class, 'disposed fixed asset cannot be changed');

    expect(fn () => $this->service->capitaliseAsset($this->asset->fresh()))
        ->toThrow(InvalidArgumentException::class, 'disposed fixed asset cannot be capitalised');

    expect((string) $this->asset->fresh()->purchase_cost)->toBe('1000.00')
        ->and(FinFixedAssetDisposal::query()->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(1);
});

it('claims the shared sequence mutex for a no-GL disposal and replays without a journal', function (): void {
    $actor = User::factory()->create(['organization_id' => 2]);
    $this->actingAs($actor);
    $asset = FinFixedAsset::factory()->create([
        'organization_id' => 2,
        'asset_name' => 'No-GL disposal asset',
        'asset_tag' => 'FA-NO-GL-DISP',
        'purchase_date' => '2025-01-01',
        'purchase_cost' => '250.00',
        'residual_value' => '0.00',
        'useful_life_months' => 12,
        'depreciation_method' => 'straight_line',
        'accumulated_depreciation' => '50.00',
        'gl_asset_account_id' => null,
        'gl_depreciation_account_id' => null,
        'status' => 'active',
    ]);

    $first = $this->service->disposeAsset($asset, fadpPayload('25.00'));
    $replay = $this->service->disposeAsset($asset, fadpPayload('25.00'));
    $disposal = FinFixedAssetDisposal::query()->where('fixed_asset_id', $asset->id)->sole();

    expect($first->id)->toBe($replay->id)
        ->and($disposal->posting_mode)->toBe(FinFixedAssetDisposal::POSTING_MODE_NO_GL)
        ->and($disposal->journal_id)->toBeNull()
        ->and((int) DB::table('fin_journal_sequences')
            ->where('organization_id', 2)->value('next_number'))->toBe(1)
        ->and(FinJournal::query()->where('organization_id', 2)->count())->toBe(0)
        ->and($asset->fresh()->status)->toBe('disposed');
});

it('rolls back the disposal occurrence terminal state journal and event on posting failure', function (): void {
    Event::fake([JournalPosted::class]);
    $this->gainLossAccount->delete();

    expect(fn () => $this->service->disposeAsset($this->asset, fadpPayload()))
        ->toThrow(InvalidArgumentException::class, 'Gain/Loss on Asset Disposal account');

    expect(FinFixedAssetDisposal::query()->count())->toBe(0)
        ->and(FinJournal::query()->count())->toBe(0)
        ->and($this->asset->fresh()->status)->toBe('active')
        ->and($this->asset->fresh()->disposed_date)->toBeNull()
        ->and($this->asset->fresh()->disposal_proceeds)->toBeNull();
    Event::assertNotDispatched(JournalPosted::class);
});

it('discards JournalPosted when an outer transaction rolls back', function (): void {
    Event::fake([JournalPosted::class]);
    $connection = DB::connection();

    $connection->beginTransaction();
    try {
        $this->service->disposeAsset($this->asset, fadpPayload());
        Event::assertNotDispatched(JournalPosted::class);

        $connection->commit();
        Event::assertNotDispatched(JournalPosted::class);

        $connection->rollBack();
        Event::assertNotDispatched(JournalPosted::class);

        expect(FinFixedAssetDisposal::query()->count())->toBe(0)
            ->and(FinJournal::query()->count())->toBe(0);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $connection->beginTransaction();
    }
});

it('dispatches JournalPosted once and only after the outermost commit', function (): void {
    Event::fake([JournalPosted::class]);
    $connection = DB::connection();
    $actorId = $this->actor->id;

    $connection->beginTransaction();
    try {
        $this->service->disposeAsset($this->asset, fadpPayload());
        Event::assertNotDispatched(JournalPosted::class);

        $connection->commit();
        Event::assertNotDispatched(JournalPosted::class);

        $connection->commit();
        Event::assertDispatched(JournalPosted::class, function (JournalPosted $event): bool {
            return $event->journal->source_type === FinFixedAssetDisposal::class
                && $event->journal->status === 'posted';
        });
        Event::assertDispatchedTimes(JournalPosted::class, 1);
    } finally {
        fadpResetCommittedFixtures($connection, [$actorId]);
    }
});

it('serializes independent MySQL workers onto one disposal occurrence', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $database = $connection->getDatabaseName();
    $assetId = $this->asset->id;
    $actorId = $this->actor->id;
    $connection->commit();

    try {
        $results = fadpConcurrentRound($database, $assetId);

        expect(array_unique(array_column($results, 'asset_id')))->toBe([$assetId])
            ->and(array_unique(array_column($results, 'disposal_id')))->toHaveCount(1)
            ->and(array_unique(array_column($results, 'journal_id')))->toHaveCount(1)
            ->and(FinFixedAssetDisposal::query()->count())->toBe(1)
            ->and(FinJournal::query()->count())->toBe(1)
            ->and($this->asset->fresh()->status)->toBe('disposed');
    } finally {
        fadpResetCommittedFixtures($connection, [$actorId]);
    }
});

function fadpAccount(int $organizationId, string $code, string $name, string $type): FinAccount
{
    return FinAccount::factory()->create([
        'organization_id' => $organizationId,
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'is_active' => true,
    ]);
}

/** @return array{disposed_date:string,disposal_proceeds:string} */
function fadpPayload(string $proceeds = '500.00'): array
{
    return [
        'disposed_date' => '2026-08-20',
        'disposal_proceeds' => $proceeds,
    ];
}

/** @return list<array{asset_id:int,disposal_id:int,journal_id:int|null}> */
function fadpConcurrentRound(string $database, int $assetId): array
{
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fadp-release-{$token}";
    $readyPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."fadp-ready-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."fadp-ready-b-{$token}",
    ];
    $processes = [];

    try {
        foreach ($readyPaths as $readyPath) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/FixedAssetDisposalWorker.php'),
                $database,
                (string) $assetId,
                $readyPath,
                $releasePath,
            ]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        fadpWaitForFiles($readyPaths, 'The disposal workers did not reach their shared start barrier.');
        touch($releasePath);

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A disposal worker failed.');
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
        foreach ([...$readyPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

/** @param list<int> $actorIds */
function fadpResetCommittedFixtures(ConnectionInterface $connection, array $actorIds): void
{
    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    DB::table('fin_fixed_asset_disposals')->delete();
    DB::table('fin_journal_lines')->delete();
    DB::table('fin_journals')->delete();
    DB::table('fin_fixed_assets')->delete();
    DB::table('fin_fiscal_periods')->delete();
    DB::table('fin_accounts')->delete();
    DB::table('fin_journal_sequences')->delete();
    DB::table('audit_logs')->delete();
    DB::table('permission_user')->whereIn('user_id', $actorIds)->delete();
    DB::table('users')->whereIn('id', $actorIds)->delete();

    $connection->beginTransaction();
}

/** @param list<string> $paths */
function fadpWaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
