<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDisposal;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FixedAssetService;
use App\Models\User;

/**
 * Journal-posting lock-in for the fixed-asset dispose modal. Disposing an asset
 * that has a GL asset account mapped must post a single BALANCED journal that
 * clears the asset at cost (CR), removes accumulated depreciation (DR), banks
 * the proceeds (DR 1000), and books the gain/loss on disposal to the configured
 * account (8400 — NOT 8100, which the chart uses for Bank Fees). It must also
 * mark the asset disposed with the recorded date and proceeds. And if a
 * gain/loss arises but the 8400 account is missing, it must THROW (never post an
 * unbalanced journal + silently roll the disposal back).
 */
function seedDisposalAccounts(bool $withGainLoss = true): void
{
    $accounts = [
        ['1000', 'Bank', 'asset'],
        ['1500', 'Motor Vehicles', 'asset'],
        ['1590', 'Accumulated Depreciation', 'asset'],
    ];
    if ($withGainLoss) {
        $accounts[] = ['8400', 'Gain/Loss on Asset Disposal', 'expense'];
    }
    foreach ($accounts as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
}

/** @return array{0:string,1:string} [debits, credits] summed to 2dp. */
function disposalJournalTotals(FinJournal $journal): array
{
    $journal->loadMissing('lines');

    return [
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0'),
        $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0'),
    ];
}

it('disposing a mapped asset posts a single balanced disposal journal', function () {
    seedDisposalAccounts();
    $assetAcct = FinAccount::where('organization_id', 1)->where('code', '1500')->firstOrFail();
    $accumAcct = FinAccount::where('organization_id', 1)->where('code', '1590')->firstOrFail();

    // Cost 1,000; accumulated 400 → book value 600. Proceeds 500 → loss 100.
    $asset = FinFixedAsset::factory()->create([
        'organization_id' => 1,
        'status' => 'active',
        'purchase_cost' => '1000.00',
        'accumulated_depreciation' => '400.00',
        'residual_value' => 0,
        'gl_asset_account_id' => $assetAcct->id,
        'gl_depreciation_account_id' => $accumAcct->id,
    ]);
    $user = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($user);

    $result = app(FixedAssetService::class)->disposeAsset($asset, [
        'disposed_date' => now()->toDateString(),
        'disposal_proceeds' => '500.00',
    ]);

    expect($result->status)->toBe('disposed')
        ->and((string) $result->disposal_proceeds)->toBe('500.00');

    $disposal = FinFixedAssetDisposal::query()
        ->where('fixed_asset_id', $asset->id)
        ->sole();
    $journal = $disposal->journal()->firstOrFail();
    [$debits, $credits] = disposalJournalTotals($journal);

    // DR bank 500 + DR accum 400 + DR loss 100 = 1,000; CR asset 1,000.
    expect($journal->status)->toBe('posted')
        ->and(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('1000.00');

    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);
    expect($cr->account->code)->toBe('1500'); // asset cleared at cost

    // The gain/loss (loss 100) posts to 8400, not the old hardcoded 8100.
    $loss = $journal->lines->first(fn ($l) => $l->account->code === '8400');
    expect($loss)->not->toBeNull()
        ->and((string) $loss->debit)->toBe('100.00');

    // Exactly one disposal journal for this asset.
    expect($journal->source_type)->toBe(FinFixedAssetDisposal::class)
        ->and($journal->source_id)->toBe($disposal->id)
        ->and(FinJournal::where('source_type', FinFixedAssetDisposal::class)
            ->where('source_id', $disposal->id)->count())->toBe(1);
});

it('refuses to dispose at a gain/loss when the 8400 account is missing (no unbalanced journal)', function () {
    seedDisposalAccounts(withGainLoss: false); // 8400 deliberately absent
    $assetAcct = FinAccount::where('organization_id', 1)->where('code', '1500')->firstOrFail();
    $accumAcct = FinAccount::where('organization_id', 1)->where('code', '1590')->firstOrFail();

    // Cost 1,000; accumulated 400 → book value 600. Proceeds 500 → loss 100 (needs 8400).
    $asset = FinFixedAsset::factory()->create([
        'organization_id' => 1,
        'status' => 'active',
        'purchase_cost' => '1000.00',
        'accumulated_depreciation' => '400.00',
        'residual_value' => 0,
        'gl_asset_account_id' => $assetAcct->id,
        'gl_depreciation_account_id' => $accumAcct->id,
    ]);
    $this->actingAs(User::factory()->create(['organization_id' => 1]));

    expect(fn () => app(FixedAssetService::class)->disposeAsset($asset, [
        'disposed_date' => now()->toDateString(),
        'disposal_proceeds' => '500.00',
    ]))->toThrow(InvalidArgumentException::class);

    // Rolled back: asset still active, no journal posted.
    expect($asset->refresh()->status)->toBe('active')
        ->and(FinFixedAssetDisposal::where('fixed_asset_id', $asset->id)->count())->toBe(0)
        ->and(FinJournal::where('source_type', FinFixedAssetDisposal::class)->count())->toBe(0);
});
