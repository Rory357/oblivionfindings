<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The invoices index attaches each row's outstanding balance (amount_due) so the
 * Record-Receipt modal can default + cap the receipt; and exposes canManage. A
 * partial receipt posted through finance.receivables.allocate reduces it.
 */
function arManageUser(Site $site): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ar.view', 'finance.ar.manage'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    ensureCanonicalHrStaffProfile($user, $site);

    return $user;
}

beforeEach(function () {
    foreach ([['1000', 'Bank - Operating'], ['1100', 'Accounts Receivable']] as [$code, $name]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name,
            'type' => 'asset', 'opening_balance' => 0, 'is_active' => true,
        ]);
    }
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(), 'status' => 'open',
    ]);

    $this->site = Site::factory()->create();
    $this->client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $this->site->id,
    ]);
    $this->invoice = FinInvoice::factory()->create([
        'organization_id' => 1, 'client_id' => $this->client->id, 'status' => 'sent',
        'total_amount' => '100.00', 'invoice_date' => now()->subDays(5), 'due_date' => now()->addDays(20),
    ]);
});

it('index exposes full amount_due and canManage when nothing is paid', function () {
    $this->actingAs(arManageUser($this->site))
        ->get(route('finance.invoices.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/invoices/Index')
            ->where('canManage', true)
            ->where('invoices.data.0.amount_due', fn ($v) => (float) $v === 100.0)
            ->where('invoices.data.0.amount_paid', fn ($v) => (float) $v === 0.0)
        );
});

it('index nets prior allocations into amount_due', function () {
    FinPaymentAllocation::create([
        'organization_id' => 1, 'type' => 'receivable', 'payment_date' => now()->subDay(),
        'amount' => '40.00', 'allocatable_type' => FinInvoice::class, 'allocatable_id' => $this->invoice->id,
    ]);

    $this->actingAs(arManageUser($this->site))
        ->get(route('finance.invoices.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.amount_due', fn ($v) => (float) $v === 60.0)
            ->where('invoices.data.0.amount_paid', fn ($v) => (float) $v === 40.0)
        );
});

it('a partial receipt posted via allocate reduces the outstanding shown on the index', function () {
    $user = arManageUser($this->site);

    $this->actingAs($user)->post(route('finance.receivables.allocate'), [
        'invoice_id' => $this->invoice->id,
        'amount' => '30.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect();

    $this->actingAs($user)
        ->get(route('finance.invoices.index'))
        ->assertInertia(fn (Assert $page) => $page->where('invoices.data.0.amount_due', fn ($v) => (float) $v === 70.0));
});
