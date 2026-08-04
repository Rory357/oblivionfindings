<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteDamage;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * C7a capture-at-source: marking a site damage 'repaired' with an actual cost
 * records a DRAFT accounts-payable bill for the repair (Property Repairs vendor,
 * GL 6420). Draft = GL-safe; the balanced journal posts on approval. Idempotent
 * on the "DAMAGE-{id}" reference. Helper prefixed `drbc_` (unique Pest fn space).
 */
beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    $this->site = Site::factory()->create(['type' => 'house']);
    $this->financeContext = 1;

    foreach ([['1000', 'Bank', 'asset'], ['2000', 'Accounts Payable', 'liability'], ['6420', 'Property Maintenance Expense', 'expense'], ['4230', 'Insurance Recoveries', 'revenue']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => $this->financeContext, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }
    FinFiscalPeriod::create([
        'organization_id' => $this->financeContext, 'name' => 'FY', 'status' => 'open',
        'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(),
    ]);
});

function drbc_damage(Site $site, int $reporterId): SiteDamage
{
    return $site->damages()->create([
        'reported_by' => $reporterId,
        'title' => 'Broken window',
        'description' => 'Storm damage in lounge.',
        'severity' => 'moderate',
        'status' => 'repair_in_progress',
        'damage_date' => '2026-02-18',
        'discovered_date' => '2026-02-18',
    ]);
}

it('captures a repaired damage with an actual cost as a draft AP bill', function () {
    $damage = drbc_damage($this->site, $this->admin->id);

    $this->actingAs($this->admin)
        ->put("/sites/{$this->site->id}/damages/{$damage->id}", ['status' => 'repaired', 'actual_cost' => 500.00])
        ->assertRedirect();

    $bill = FinBill::where('vendor_reference', "DAMAGE-{$damage->id}")->first();
    expect($bill)->not->toBeNull()
        ->and($bill->status)->toBe('draft')
        ->and($bill->organization_id)->toBe($this->financeContext)
        ->and((float) $bill->total_amount)->toBe(500.0)
        ->and((float) $bill->subtotal)->toBe(500.0);

    expect(FinVendor::find($bill->vendor_id)->name)->toBe('Property Repairs');
    expect(FinAccount::find($bill->lines()->first()->account_id)->code)->toBe('6420');
});

it('is idempotent — re-updating a repaired damage does not create a second bill', function () {
    $damage = drbc_damage($this->site, $this->admin->id);
    $url = "/sites/{$this->site->id}/damages/{$damage->id}";

    $this->actingAs($this->admin)->put($url, ['status' => 'repaired', 'actual_cost' => 500.00])->assertRedirect();
    $this->actingAs($this->admin)->put($url, ['status' => 'repaired', 'actual_cost' => 500.00, 'repair_notes' => 'signed off'])->assertRedirect();

    expect(FinBill::where('vendor_reference', "DAMAGE-{$damage->id}")->count())->toBe(1);
});

it('approving the captured bill posts a balanced DR 6420 / CR 2000 journal', function () {
    $damage = drbc_damage($this->site, $this->admin->id);
    $this->actingAs($this->admin)
        ->put("/sites/{$this->site->id}/damages/{$damage->id}", ['status' => 'repaired', 'actual_cost' => 500.00])
        ->assertRedirect();

    $bill = FinBill::where('vendor_reference', "DAMAGE-{$damage->id}")->firstOrFail();
    $approved = app(AccountsPayableService::class)->approveBill($bill, $this->admin->id);

    expect($approved->status)->toBe('approved')
        ->and($approved->journal_id)->not->toBeNull();

    $journal = FinJournal::with('lines.account')->find($approved->journal_id);
    $dr = $journal->lines->reduce(fn ($t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $cr = $journal->lines->reduce(fn ($t, $l) => bcadd($t, (string) $l->credit, 2), '0');
    $drLine = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $crLine = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect(bccomp($dr, $cr, 2))->toBe(0)
        ->and($dr)->toBe('500.00')
        ->and($drLine->account->code)->toBe('6420')
        ->and($crLine->account->code)->toBe('2000');
});

it('does not capture a bill when the repair has no actual cost', function () {
    $damage = drbc_damage($this->site, $this->admin->id);

    $this->actingAs($this->admin)
        ->put("/sites/{$this->site->id}/damages/{$damage->id}", ['status' => 'repaired'])
        ->assertRedirect();

    expect(FinBill::where('vendor_reference', "DAMAGE-{$damage->id}")->exists())->toBeFalse();
});

it('captures an approved insurance claim as a draft AR invoice to the insurer', function () {
    $damage = drbc_damage($this->site, $this->admin->id);

    $this->actingAs($this->admin)
        ->put("/sites/{$this->site->id}/damages/{$damage->id}", [
            'status' => 'repaired',
            'actual_cost' => 500.00,
            'insurance_status' => 'approved',
            'insurance_claim_ref' => 'CLM-4471',
        ])
        ->assertRedirect();

    $invoice = FinInvoice::where('source_type', SiteDamage::class)
        ->where('source_id', $damage->id)->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->client_name)->toBe('Insurance — claim CLM-4471')
        ->and($invoice->funding_body)->toBe('Insurance')
        ->and((float) $invoice->total_amount)->toBe(500.0) // zero-rated, mirrors the gst-0 repair bill
        ->and($invoice->lines()->first()->account_id)
        ->toBe(FinAccount::where('organization_id', $this->financeContext)->where('code', '4230')->value('id'));
});

it('insurance capture is idempotent and falls back to the estimate when unpriced', function () {
    $damage = drbc_damage($this->site, $this->admin->id);
    $damage->update(['estimated_cost' => 350.00]);
    $url = "/sites/{$this->site->id}/damages/{$damage->id}";

    $this->actingAs($this->admin)->put($url, ['insurance_status' => 'approved'])->assertRedirect();
    $this->actingAs($this->admin)->put($url, ['insurance_status' => 'approved', 'repair_notes' => 'again'])->assertRedirect();

    $invoices = FinInvoice::where('source_type', SiteDamage::class)
        ->where('source_id', $damage->id)->get();

    expect($invoices)->toHaveCount(1)
        ->and((float) $invoices->first()->total_amount)->toBe(350.0);
});

it('does not raise an insurance invoice for pending or declined claims', function () {
    $damage = drbc_damage($this->site, $this->admin->id);

    foreach (['pending', 'submitted', 'declined'] as $status) {
        $this->actingAs($this->admin)
            ->put("/sites/{$this->site->id}/damages/{$damage->id}", [
                'actual_cost' => 500.00,
                'insurance_status' => $status,
            ])
            ->assertRedirect();
    }

    expect(FinInvoice::where('source_type', SiteDamage::class)
        ->where('source_id', $damage->id)->exists())->toBeFalse();
});
