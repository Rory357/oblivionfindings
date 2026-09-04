<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Services\FixedAssetService;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('disposing fixed asset automatically terminalizes linked operational asset projection', function () {
    // Seed finance accounts
    foreach ([
        ['1000', 'Bank', 'asset'],
        ['1500', 'Motor Vehicles', 'asset'],
        ['1590', 'Accumulated Depreciation', 'asset'],
        ['8400', 'Gain/Loss on Asset Disposal', 'expense'],
    ] as [$code, $name, $type]) {
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

    $assetAcct = FinAccount::where('organization_id', 1)->where('code', '1500')->firstOrFail();
    $accumAcct = FinAccount::where('organization_id', 1)->where('code', '1590')->firstOrFail();

    $user = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($user);

    // Create operational asset
    $operationalAsset = Asset::factory()->create([
        'name' => 'Fleet Vehicle 01',
        'category' => 'vehicle',
        'status' => 'active',
    ]);

    // Create fixed asset linked to operational asset
    $fixedAsset = FinFixedAsset::factory()->create([
        'organization_id' => 1,
        'linked_asset_id' => $operationalAsset->id,
        'status' => 'active',
        'purchase_cost' => '1000.00',
        'accumulated_depreciation' => '400.00',
        'residual_value' => 0,
        'gl_asset_account_id' => $assetAcct->id,
        'gl_depreciation_account_id' => $accumAcct->id,
    ]);

    // Dispose the fixed asset
    $disposed = app(FixedAssetService::class)->disposeAsset($fixedAsset, [
        'disposed_date' => now()->toDateString(),
        'disposal_proceeds' => '500.00',
    ]);

    expect($disposed->status)->toBe('disposed');

    // Operational asset must be terminalized
    $operationalAsset->refresh();
    expect($operationalAsset->status)->toBe('disposed');
});
