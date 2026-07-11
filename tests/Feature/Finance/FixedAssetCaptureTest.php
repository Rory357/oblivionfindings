<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FixedAssetService;
use App\Models\Asset;
use App\Models\AssetValue;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * C7d asset capitalisation capture. A high-value operational asset valuation
 * lands on the fixed-asset register WITHOUT GL accounts (no auto journal —
 * capitalisation policy stays a finance decision); finance assigns the GL
 * asset account and posts the acquisition explicitly via capitaliseAsset,
 * which is idempotent and tracked on acquisition_journal_id. Helpers `fac_*`.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create(['organization_id' => 1]));
    $this->site = Site::factory()->create(['type' => 'house', 'tenant_id' => 1]);

    foreach ([['1000', 'Bank', 'asset'], ['1500', 'Motor Vehicles', 'asset']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY', 'status' => 'open',
        'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(),
    ]);
});

function fac_vehicle(int $siteId): Asset
{
    return Asset::factory()->create([
        'site_id' => $siteId,
        'name' => 'Toyota Hiace',
        'category' => 'vehicle',
        'purchase_date' => now()->subDays(3)->toDateString(),
    ]);
}

it('registers a high-value asset valuation on the fixed-asset register without posting', function () {
    $asset = fac_vehicle($this->site->id);

    AssetValue::create(['asset_id' => $asset->id, 'purchase_cost' => 42000.00]);

    $fixed = FinFixedAsset::where('linked_asset_id', $asset->id)->first();
    expect($fixed)->not->toBeNull()
        ->and($fixed->asset_name)->toBe('Toyota Hiace')
        ->and($fixed->category)->toBe('vehicle')
        ->and((float) $fixed->purchase_cost)->toBe(42000.0)
        ->and($fixed->gl_asset_account_id)->toBeNull()
        ->and($fixed->acquisition_journal_id)->toBeNull(); // registered, NOT posted

    expect(FinJournal::where('source_type', FinFixedAsset::class)->where('source_id', $fixed->id)->exists())->toBeFalse();
});

it('ignores low-value and non-capital valuations, and never duplicates', function () {
    $cheap = fac_vehicle($this->site->id);
    AssetValue::create(['asset_id' => $cheap->id, 'purchase_cost' => 200.00]); // below threshold

    $furnitureish = Asset::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Whiteboard', 'category' => 'stationery',
    ]);
    AssetValue::create(['asset_id' => $furnitureish->id, 'purchase_cost' => 5000.00]); // non-capital category

    expect(FinFixedAsset::count())->toBe(0);

    $van = fac_vehicle($this->site->id);
    $value = AssetValue::create(['asset_id' => $van->id, 'purchase_cost' => 42000.00]);
    $value->update(['current_value' => 39000.00]); // re-fires updated → must not duplicate

    expect(FinFixedAsset::where('linked_asset_id', $van->id)->count())->toBe(1);
});

it('capitaliseAsset posts a balanced acquisition journal once the GL account is assigned', function () {
    $asset = fac_vehicle($this->site->id);
    AssetValue::create(['asset_id' => $asset->id, 'purchase_cost' => 42000.00]);
    $fixed = FinFixedAsset::where('linked_asset_id', $asset->id)->firstOrFail();

    $glAsset = FinAccount::where('organization_id', 1)->where('code', '1500')->firstOrFail();
    $fixed->update(['gl_asset_account_id' => $glAsset->id]);

    $capitalised = app(FixedAssetService::class)->capitaliseAsset($fixed->fresh());

    expect($capitalised->acquisition_journal_id)->not->toBeNull();

    $journal = FinJournal::with('lines.account')->find($capitalised->acquisition_journal_id);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);
    $totDr = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $totCr = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($totDr, $totCr, 2))->toBe(0)
        ->and($totDr)->toBe('42000.00')
        ->and($dr->account->code)->toBe('1500')
        ->and($cr->account->code)->toBe('1000');

    // idempotent — a second capitalise throws, no second journal
    expect(fn () => app(FixedAssetService::class)->capitaliseAsset($capitalised->fresh()))
        ->toThrow(InvalidArgumentException::class);
    expect(FinJournal::where('source_type', FinFixedAsset::class)->where('source_id', $fixed->id)->count())->toBe(1);
});

it('capitaliseAsset refuses an asset with no GL asset account', function () {
    $asset = fac_vehicle($this->site->id);
    AssetValue::create(['asset_id' => $asset->id, 'purchase_cost' => 42000.00]);
    $fixed = FinFixedAsset::where('linked_asset_id', $asset->id)->firstOrFail();

    expect(fn () => app(FixedAssetService::class)->capitaliseAsset($fixed))
        ->toThrow(InvalidArgumentException::class);
});

it('the manual create-with-GL-account path still auto-posts and now records the journal id', function () {
    $glAsset = FinAccount::where('organization_id', 1)->where('code', '1500')->firstOrFail();

    $fixed = app(FixedAssetService::class)->createAsset(1, [
        'asset_name' => 'Office van',
        'category' => 'vehicle',
        'purchase_date' => now()->toDateString(),
        'purchase_cost' => 18000,
        'useful_life_months' => 60,
        'depreciation_method' => 'straight_line',
        'gl_asset_account_id' => $glAsset->id,
    ]);

    expect($fixed->fresh()->acquisition_journal_id)->not->toBeNull();
});
