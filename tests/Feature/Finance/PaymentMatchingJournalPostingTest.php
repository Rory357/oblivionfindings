<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\PaymentMatchingService;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PaymentMatchingJournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        app(FinanceSeeder::class)->run($this->orgId);

        FinFiscalPeriod::create([
            'organization_id' => $this->orgId,
            'name' => 'FY2026',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
        ]);
    }

    public function test_confirming_bill_payment_match_posts_ap_bank_journal_and_is_idempotent(): void
    {
        $user = $this->globalPaymentManager();
        $site = Site::factory()->create();
        $bankAccount = $this->bankAccount();
        $vendor = FinVendor::factory()->create(['organization_id' => $this->orgId]);
        $bill = FinBill::factory()->create([
            'organization_id' => $this->orgId,
            'vendor_id' => $vendor->id,
            'site_id' => $site->id,
            'bill_number' => 'BILL-MATCH-001',
            'status' => 'approved',
            'total_amount' => 250,
            'amount_paid' => 0,
        ]);
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => -250,
            'description' => 'Payment BILL-MATCH-001',
            'reference' => 'BANK-BILL-001',
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);
        $match = FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'site_id' => $site->id,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinBill::class,
            'matchable_id' => $bill->id,
            'suggestion_key' => "{$transaction->id}:".FinBill::class.":{$bill->id}",
            'confidence_score' => 99,
            'match_reasons' => ['Exact amount match'],
            'status' => 'suggested',
        ]);

        $confirmed = app(PaymentMatchingService::class)->confirmMatch($match, $user);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->journal_id);

        $journal = FinJournal::with('lines.account')->findOrFail($confirmed->journal_id);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(FinPaymentMatch::class, $journal->source_type);
        $this->assertSame($match->id, $journal->source_id);
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '2000'
                && (string) $line->debit === '250.00'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1000'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '250.00'
        ));

        $this->assertSame('250.00', (string) $bill->refresh()->amount_paid);
        $this->assertSame('paid', $bill->status);
        $this->assertSame(1, FinPaymentAllocation::where('allocatable_type', FinBill::class)
            ->where('allocatable_id', $bill->id)
            ->where('journal_id', $journal->id)
            ->count());

        app(PaymentMatchingService::class)->confirmMatch($confirmed, $user);

        $this->assertSame(1, FinJournal::where('source_type', FinPaymentMatch::class)
            ->where('source_id', $match->id)
            ->count());
        $this->assertSame(1, FinPaymentAllocation::where('source_type', FinPaymentMatch::class)
            ->where('source_id', $match->id)
            ->count());

        try {
            app(PaymentMatchingService::class)->rejectMatch($confirmed->fresh(), $user);
            self::fail('A confirmed settlement must not be rejected without a reversal.');
        } catch (\InvalidArgumentException) {
            // Expected: rejection is only a pre-settlement suggestion action.
        }

        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame(1, FinPaymentAllocation::where('source_type', FinPaymentMatch::class)
            ->where('source_id', $match->id)
            ->count());
    }

    public function test_confirming_invoice_receipt_match_posts_bank_ar_journal_and_marks_paid(): void
    {
        $user = $this->globalPaymentManager();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $bankAccount = $this->bankAccount();
        $invoice = FinInvoice::factory()->create([
            'organization_id' => $this->orgId,
            'client_id' => $client->id,
            'invoice_number' => 'INV-MATCH-001',
            'status' => 'sent',
            'subtotal' => 100,
            'tax_amount' => 15,
            'total_amount' => 115,
        ]);
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => 115,
            'description' => 'Receipt INV-MATCH-001',
            'reference' => 'BANK-INV-001',
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);
        $match = FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'site_id' => $site->id,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinInvoice::class,
            'matchable_id' => $invoice->id,
            'suggestion_key' => "{$transaction->id}:".FinInvoice::class.":{$invoice->id}",
            'confidence_score' => 99,
            'match_reasons' => ['Exact amount match'],
            'status' => 'suggested',
        ]);

        $confirmed = app(PaymentMatchingService::class)->confirmMatch($match, $user);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->journal_id);

        $journal = FinJournal::with('lines.account')->findOrFail($confirmed->journal_id);
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1000'
                && (string) $line->debit === '115.00'
                && (string) $line->credit === '0.00'
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account->code === '1100'
                && (string) $line->debit === '0.00'
                && (string) $line->credit === '115.00'
        ));

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(1, FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->where('allocatable_id', $invoice->id)
            ->where('journal_id', $journal->id)
            ->count());
    }

    public function test_global_payment_viewer_sees_active_site_match_history_but_cannot_take_match_actions(): void
    {
        $assignedSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $assignedClient = Client::factory()->create(['site_id' => $assignedSite->id]);
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $assignedInvoice = FinInvoice::factory()->create([
            'organization_id' => $this->orgId,
            'client_id' => $assignedClient->id,
            'status' => 'sent',
            'total_amount' => '10.00',
        ]);
        $otherInvoice = FinInvoice::factory()->create([
            'organization_id' => $this->orgId,
            'client_id' => $otherClient->id,
            'status' => 'sent',
            'total_amount' => '20.00',
        ]);
        $bankAccount = $this->bankAccount();
        $globalViewer = $this->auditorPaymentViewer();
        $siteViewer = $this->paymentUser($assignedSite, ['finance.bank.view']);

        $suggested = $this->historyMatch($bankAccount, $assignedSite, $assignedInvoice, 'suggested', 'history-suggested');
        $rejected = $this->historyMatch($bankAccount, $otherSite, $otherInvoice, 'rejected', 'history-rejected', $globalViewer);
        $confirmed = $this->historyMatch($bankAccount, $otherSite, $otherInvoice, 'confirmed', 'history-confirmed');

        foreach ([
            ['suggested', $suggested->id],
            ['rejected', $rejected->id],
            ['all_confirmed', $confirmed->id],
        ] as [$status, $matchId]) {
            $this->actingAs($globalViewer)
                ->get(route('finance.payment-matching.index', ['status' => $status]))
                ->assertOk()
                ->assertInertia(fn (Assert $page): Assert => $page
                    ->where('matches.total', 1)
                    ->where('matches.data.0.id', $matchId));
        }

        $this->actingAs($globalViewer)
            ->post(route('finance.payment-matching.confirm', $suggested))
            ->assertForbidden();
        $this->actingAs($globalViewer)
            ->post(route('finance.payment-matching.reject', $suggested))
            ->assertForbidden();

        $this->actingAs($siteViewer)
            ->get(route('finance.payment-matching.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('matches.total', 1)
                ->where('matches.data.0.id', $suggested->id));
    }

    public function test_rejection_audit_failure_leaves_the_suggestion_and_settlement_unchanged(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $invoice = FinInvoice::factory()->create([
            'organization_id' => $this->orgId,
            'client_id' => $client->id,
            'invoice_number' => 'INV-REJECT-001',
            'status' => 'sent',
            'total_amount' => '45.00',
        ]);
        $bankAccount = $this->bankAccount();
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => '45.00',
            'description' => 'Receipt INV-REJECT-001',
            'reference' => 'INV-REJECT-001',
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);
        $baseKey = "{$transaction->id}:".FinInvoice::class.":{$invoice->id}";
        $match = FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'site_id' => $site->id,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinInvoice::class,
            'matchable_id' => $invoice->id,
            'suggestion_key' => $baseKey,
            'confidence_score' => 99,
            'status' => 'suggested',
        ]);
        $actor = $this->globalPaymentManager();
        $blockedAudit = true;

        DB::listen(function ($query) use (&$blockedAudit): void {
            if ($blockedAudit && str_contains(strtolower($query->sql), 'insert into `audit_logs`')) {
                throw new \RuntimeException('Forced rejection audit failure.');
            }
        });

        try {
            try {
                app(PaymentMatchingService::class)->rejectMatch($match, $actor, 'Not this receipt');
                self::fail('A failed rejection audit must abort the transition.');
            } catch (\RuntimeException) {
                // Expected fail-closed audit boundary.
            }
        } finally {
            $blockedAudit = false;
        }

        $this->assertSame('suggested', $match->fresh()->status);
        $this->assertNull($match->rejected_by);
        $this->assertNull($match->rejected_at);
        $this->assertNull($match->rejection_reason);
        $this->assertSame(0, AuditLog::where('action', 'finance.payment_match.rejected')->count());
        $this->assertSame(0, FinJournal::where('source_type', FinPaymentMatch::class)->count());
        $this->assertSame(0, FinPaymentAllocation::query()->count());
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_successful_rejection_has_no_settlement_effect_and_resuggestion_appends_a_new_version(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $invoice = FinInvoice::factory()->create([
            'organization_id' => $this->orgId,
            'client_id' => $client->id,
            'invoice_number' => 'INV-REJECT-002',
            'status' => 'sent',
            'total_amount' => '55.00',
        ]);
        $bankAccount = $this->bankAccount();
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => '55.00',
            'description' => 'Receipt INV-REJECT-002',
            'reference' => 'INV-REJECT-002',
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);
        $baseKey = "{$transaction->id}:".FinInvoice::class.":{$invoice->id}";
        $match = FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'site_id' => $site->id,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinInvoice::class,
            'matchable_id' => $invoice->id,
            'suggestion_key' => $baseKey,
            'confidence_score' => 99,
            'status' => 'suggested',
        ]);
        $actor = $this->globalPaymentManager();

        $rejected = app(PaymentMatchingService::class)
            ->rejectMatch($match, $actor, 'Duplicate bank narrative');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame($actor->id, $rejected->rejected_by);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame('Duplicate bank narrative', $rejected->rejection_reason);
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, FinJournal::where('source_type', FinPaymentMatch::class)->count());
        $this->assertSame(0, FinPaymentAllocation::query()->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'finance.payment_match.rejected')
            ->where('user_id', $actor->id)
            ->where('auditable_type', FinPaymentMatch::class)
            ->where('auditable_id', $match->id)
            ->count());

        try {
            $rejected->update(['status' => 'suggested']);
            self::fail('Rejected match history must not be reopened.');
        } catch (\LogicException) {
            // Expected append-only terminal policy.
        }

        $created = app(PaymentMatchingService::class)
            ->suggestForTransaction($this->orgId, $transaction, $actor);
        $secondProposal = FinPaymentMatch::query()
            ->where('bank_transaction_id', $transaction->id)
            ->where('id', '!=', $match->id)
            ->sole();

        $this->assertSame(1, $created);
        $this->assertSame('rejected', $match->fresh()->status);
        $this->assertSame($baseKey.':v2', $secondProposal->suggestion_key);
        $this->assertSame('suggested', $secondProposal->status);
        $this->assertNull($secondProposal->rejected_by);
        $this->assertNull($secondProposal->rejected_at);
    }

    public function test_wrong_site_confirmation_is_concealed_and_explicit_global_confirmation_succeeds(): void
    {
        $assignedSite = Site::factory()->create();
        $targetSite = Site::factory()->create();
        $siteUser = User::factory()->create(['organization_id' => $this->orgId, 'approved_at' => now()]);
        ensureCanonicalHrStaffProfile($siteUser, $assignedSite);
        $client = Client::factory()->create(['site_id' => $targetSite->id]);
        $invoice = FinInvoice::factory()->create([
            'organization_id' => $this->orgId,
            'client_id' => $client->id,
            'status' => 'sent',
            'total_amount' => '25.00',
        ]);
        $bankAccount = $this->bankAccount();
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => '25.00',
            'description' => 'Receipt',
            'status' => 'unreconciled',
        ]);
        $match = FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'site_id' => $targetSite->id,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinInvoice::class,
            'matchable_id' => $invoice->id,
            'suggestion_key' => "{$transaction->id}:".FinInvoice::class.":{$invoice->id}",
            'confidence_score' => 99,
            'status' => 'suggested',
        ]);

        try {
            app(PaymentMatchingService::class)->confirmMatch($match, $siteUser);
            self::fail('A wrong-Site match target must be concealed.');
        } catch (NotFoundHttpException) {
            // Expected privacy-safe denial.
        }

        $this->assertSame(0, FinPaymentAllocation::query()->count());
        $this->assertSame(0, FinJournal::where('source_type', FinPaymentMatch::class)->count());

        $confirmed = app(PaymentMatchingService::class)->confirmMatch($match->fresh(), $this->globalPaymentManager());
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(1, FinPaymentAllocation::query()->count());
    }

    private function bankAccount(): FinBankAccount
    {
        $account = FinAccount::forOrganization($this->orgId)
            ->where('code', '1000')
            ->firstOrFail();

        return FinBankAccount::factory()->create([
            'organization_id' => $this->orgId,
            'gl_account_id' => $account->id,
            'account_type' => 'cheque',
        ]);
    }

    private function globalPaymentManager(): User
    {
        return $this->paymentUser(null, [
            'finance.bank.manage',
            PaymentSettlementSiteScope::GLOBAL_PERMISSION,
        ]);
    }

    /** @param list<string> $permissionKeys */
    private function paymentUser(?Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create(['organization_id' => $this->orgId, 'approved_at' => now()]);
        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key],
            );
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        if ($site !== null) {
            ensureCanonicalHrStaffProfile($user, $site);
        }

        return $user;
    }

    private function auditorPaymentViewer(): User
    {
        $auditor = User::factory()->create([
            'organization_id' => $this->orgId,
            'role' => 'auditor',
            'approved_at' => now(),
        ]);
        $role = Role::query()->firstOrCreate(
            ['name' => 'auditor'],
            ['label' => 'Auditor', 'level' => 10, 'type' => 'system'],
        );
        $permissionIds = collect([
            'finance.bank.view',
            PaymentSettlementSiteScope::GLOBAL_VIEW_PERMISSION,
        ])->map(function (string $key): int {
            return Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => $key],
            )->id;
        });
        $role->permissions()->syncWithoutDetaching($permissionIds->all());
        $auditor->roles()->syncWithoutDetaching([$role->id]);

        return $auditor;
    }

    private function historyMatch(
        FinBankAccount $bankAccount,
        Site $site,
        FinInvoice $invoice,
        string $status,
        string $reference,
        ?User $rejectedBy = null,
    ): FinPaymentMatch {
        $transaction = FinBankTransaction::create([
            'organization_id' => $this->orgId,
            'bank_account_id' => $bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => (string) $invoice->total_amount,
            'description' => $reference,
            'reference' => $reference,
            'source' => 'manual',
            'status' => 'unreconciled',
        ]);

        return FinPaymentMatch::create([
            'organization_id' => $this->orgId,
            'site_id' => $site->id,
            'bank_transaction_id' => $transaction->id,
            'matchable_type' => FinInvoice::class,
            'matchable_id' => $invoice->id,
            'suggestion_key' => "{$transaction->id}:".FinInvoice::class.":{$invoice->id}",
            'confidence_score' => 99,
            'status' => $status,
            'confirmed_at' => $status === 'confirmed' ? now() : null,
            'rejected_by' => $status === 'rejected' ? $rejectedBy?->id : null,
            'rejected_at' => $status === 'rejected' ? now() : null,
        ]);
    }
}
