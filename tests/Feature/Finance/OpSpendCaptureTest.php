<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\FleetFuelLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * C7e operational-spend capture. Fuel (paid at the pump on a card) posts direct
 * to GL crediting 1180 Card Clearing — never phantom AP. Maintenance (genuine
 * on-account vendor spend) becomes a DRAFT AP bill whose approval posts the
 * journal AND the FinCostAllocation rows site reporting reads. Helpers `osc_*`.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create(['organization_id' => 1]));
    $this->site = Site::factory()->create(['type' => 'house', 'tenant_id' => 1]);
    $this->asset = Asset::factory()->create(['site_id' => $this->site->id, 'name' => 'Hilux van']);

    foreach ([['1180', 'Card Clearing', 'asset'], ['2000', 'Accounts Payable', 'liability'], ['6200', 'Fuel & Oil Expense', 'expense'], ['6300', 'Equipment Maintenance Expense', 'expense']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY', 'status' => 'open',
        'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(),
    ]);
});

it('posts fuel spend direct to GL crediting Card Clearing, never phantom AP', function () {
    $log = FleetFuelLog::create([
        'asset_id' => $this->asset->id,
        'logged_at' => now(),
        'quantity_litres' => 50,
        'cost_per_litre' => 2.40,
        'total_cost' => 120.00,
        'station_name' => 'Z Energy Newtown',
    ]);

    // sync queue → the observer's GL job ran inline
    $journal = FinJournal::where('source_type', FleetFuelLog::class)->where('source_id', $log->id)->first();
    expect($journal)->not->toBeNull();

    $journal->loadMissing('lines.account');
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect($dr->account->code)->toBe('6200')
        ->and($cr->account->code)->toBe('1180') // Card Clearing, not 2000 AP
        ->and((float) $dr->debit)->toBe(120.0);

    // site/asset attribution intact
    expect(FinCostAllocation::where('journal_id', $journal->id)
        ->where('site_id', $this->site->id)->where('asset_id', $this->asset->id)->exists())->toBeTrue();

    // and no bill — fuel is settled by bank rec, not payment runs
    expect(FinBill::where('vendor_reference', "FUEL-{$log->id}")->exists())->toBeFalse();
});

it('captures a maintenance log as a draft AP bill against the log vendor', function () {
    $log = AssetMaintenanceLog::create([
        'asset_id' => $this->asset->id,
        'performed_at' => now(),
        'type' => 'service',
        'vendor' => "Joe's Auto Repair",
        'cost' => 480.00,
    ]);

    $bill = FinBill::where('vendor_reference', "MAINT-{$log->id}")->first();
    expect($bill)->not->toBeNull()
        ->and($bill->status)->toBe('draft')
        ->and((float) $bill->total_amount)->toBe(480.0)
        ->and($bill->site_id)->toBe($this->site->id)
        ->and($bill->asset_id)->toBe($this->asset->id)
        ->and($bill->allocation_event_type)->toBe('asset_maintenance_expense')
        ->and(FinVendor::find($bill->vendor_id)->name)->toBe("Joe's Auto Repair");

    // draft = GL-safe: no journal until approval
    expect($bill->journal_id)->toBeNull()
        ->and(FinJournal::where('source_type', AssetMaintenanceLog::class)->where('source_id', $log->id)->exists())->toBeFalse();
});

it('approving the maintenance bill posts DR 6300 / CR 2000 and allocates to the site + asset', function () {
    $log = AssetMaintenanceLog::create([
        'asset_id' => $this->asset->id,
        'performed_at' => now(),
        'type' => 'repair',
        'vendor' => 'FixIt Ltd',
        'cost' => 480.00,
    ]);
    $bill = FinBill::where('vendor_reference', "MAINT-{$log->id}")->firstOrFail();

    $approved = app(AccountsPayableService::class)->approveBill($bill, auth()->id());

    $journal = FinJournal::with('lines.account')->find($approved->journal_id);
    $dr = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $cr = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);
    $totalDr = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $totalCr = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');

    expect(bccomp($totalDr, $totalCr, 2))->toBe(0)
        ->and($dr->account->code)->toBe('6300')
        ->and($cr->account->code)->toBe('2000');

    $alloc = FinCostAllocation::where('journal_id', $journal->id)->first();
    expect($alloc)->not->toBeNull()
        ->and($alloc->site_id)->toBe($this->site->id)
        ->and($alloc->asset_id)->toBe($this->asset->id)
        ->and($alloc->event_type)->toBe('asset_maintenance_expense')
        ->and((float) $alloc->amount)->toBe(480.0);
});

it('captures nothing for zero-cost logs', function () {
    $fuel = FleetFuelLog::create([
        'asset_id' => $this->asset->id, 'logged_at' => now(),
        'quantity_litres' => 10, 'cost_per_litre' => 0, 'total_cost' => 0,
    ]);
    $maint = AssetMaintenanceLog::create([
        'asset_id' => $this->asset->id, 'performed_at' => now(), 'type' => 'inspection', 'cost' => 0,
    ]);

    expect(FinJournal::where('source_type', FleetFuelLog::class)->where('source_id', $fuel->id)->exists())->toBeFalse()
        ->and(FinBill::where('vendor_reference', "MAINT-{$maint->id}")->exists())->toBeFalse();
});
