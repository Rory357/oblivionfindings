<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Marking an invoice paid used to only flip status — the AR balance the send
 * journal raised (DR 1100) was never cleared, so AR stayed overstated forever.
 * markPaid now posts a balanced receipt (DR Bank / CR AR) + a FinPaymentAllocation.
 */
function markPaidUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ar.view', 'finance.ar.manage', PaymentSettlementSiteScope::GLOBAL_PERMISSION] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

beforeEach(function () {
    foreach ([['1000', 'Bank - Operating'], ['1100', 'Accounts Receivable']] as [$code, $name]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => 'asset',
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);

    $site = Site::factory()->create();
    $this->client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
    $this->invoice = FinInvoice::factory()->create([
        'organization_id' => 1,
        'client_id' => $this->client->id,
        'status' => 'sent',
        'total_amount' => '100.00',
        'invoice_date' => now()->subDays(5),
        'due_date' => now()->addDays(20),
    ]);
});

it('marking an invoice paid posts a balanced DR Bank / CR AR receipt journal', function () {
    $this->actingAs(markPaidUser())
        ->post(route('finance.invoices.mark-paid', $this->invoice->id))
        ->assertRedirect();

    expect($this->invoice->fresh()->status)->toBe('paid');

    $allocations = FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
        ->where('allocatable_id', $this->invoice->id)
        ->get();
    expect($allocations)->toHaveCount(1)
        ->and((float) $allocations->first()->amount)->toBe(100.0);

    $journal = FinJournal::query()->findOrFail($allocations->first()->journal_id)->load('lines.account');
    $debits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->credit, 2), '0');
    $debitLine = $journal->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0);
    $creditLine = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);

    expect($journal->status)->toBe('posted')
        ->and(bccomp($debits, $credits, 2))->toBe(0)
        ->and($debits)->toBe('100.00')
        ->and($debitLine->account->code)->toBe('1000')   // Bank
        ->and($creditLine->account->code)->toBe('1100');  // AR cleared
});

it('marking paid is idempotent — a second call posts no second receipt', function () {
    $user = markPaidUser();
    $this->actingAs($user)->post(route('finance.invoices.mark-paid', $this->invoice->id));
    $this->actingAs($user)->post(route('finance.invoices.mark-paid', $this->invoice->id));

    expect(FinPaymentAllocation::where('allocatable_id', $this->invoice->id)->count())->toBe(1)
        ->and(FinJournal::where('source_type', FinInvoice::class)->where('source_id', $this->invoice->id)->count())->toBe(1);
});

it('marking a part-paid invoice paid only receipts the remaining balance', function () {
    // $40 already received via a prior allocation; markPaid should receipt the remaining $60.
    FinPaymentAllocation::create([
        'organization_id' => 1,
        'type' => 'receivable',
        'payment_date' => now()->subDay(),
        'amount' => '40.00',
        'allocatable_type' => FinInvoice::class,
        'allocatable_id' => $this->invoice->id,
    ]);

    $this->actingAs(markPaidUser())
        ->post(route('finance.invoices.mark-paid', $this->invoice->id))
        ->assertRedirect();

    $receipt = FinPaymentAllocation::where('allocatable_id', $this->invoice->id)
        ->whereNotNull('journal_id')
        ->first();
    expect((float) $receipt->amount)->toBe(60.0)
        ->and($this->invoice->fresh()->status)->toBe('paid');
});

it('denies cancellation after a partial receipt without reversing or deleting settlement evidence', function () {
    $user = markPaidUser();
    $allocation = app(AccountsReceivableService::class)->allocatePayment(1, $user, [
        'invoice_id' => $this->invoice->id,
        'amount' => '40.00',
        'payment_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $journalId = $allocation->journal_id;

    $this->actingAs($user)
        ->from(route('finance.invoices.show', $this->invoice))
        ->post(route('finance.invoices.cancel', $this->invoice))
        ->assertRedirect(route('finance.invoices.show', $this->invoice))
        ->assertSessionHasErrors('invoice');

    expect($this->invoice->fresh()->status)->toBe('sent')
        ->and(FinPaymentAllocation::query()->whereKey($allocation->id)->count())->toBe(1)
        ->and(FinJournal::query()->findOrFail($journalId)->status)->toBe('posted')
        ->and(FinJournal::query()->findOrFail($journalId)->reversed_by_journal_id)->toBeNull();
});
