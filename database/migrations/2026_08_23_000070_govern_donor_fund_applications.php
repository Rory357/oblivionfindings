<?php

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinDonorFundTransaction;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FUND_FOREIGN = 'fin_donor_fund_transactions_fund_id_foreign';

    private const BILL_FOREIGN = 'fin_donor_fund_transactions_bill_id_foreign';

    private const JOURNAL_FOREIGN = 'fin_donor_fund_transactions_journal_id_foreign';

    private const JOURNAL_UNIQUE = 'fin_donor_fund_txn_journal_unique';

    private const GLOBAL_SITE_PERMISSION = 'finance.donorFunds.manageAllSites';

    public function up(): void
    {
        $duplicateBill = DB::table('fin_donor_fund_transactions')
            ->select('bill_id')
            ->whereNotNull('bill_id')
            ->groupBy('bill_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('bill_id');

        if ($duplicateBill !== null) {
            throw new RuntimeException(
                'Donor-fund transaction migration stopped: duplicate bill applications require finance review before rollout.'
            );
        }

        $duplicateJournal = DB::table('fin_donor_fund_transactions')
            ->select('journal_id')
            ->whereNotNull('journal_id')
            ->groupBy('journal_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('journal_id');

        if ($duplicateJournal !== null) {
            throw new RuntimeException(
                'Donor-fund transaction migration stopped: duplicate journal lineage requires finance review before rollout.'
            );
        }

        $this->governExistingBillLineage();
        $this->governExistingRollForward();
        $this->governExistingJournalLineage();

        Schema::table('fin_donor_fund_transactions', function (Blueprint $table): void {
            $table->dropForeign(self::FUND_FOREIGN);
            $table->dropForeign(self::JOURNAL_FOREIGN);
            $table->dropForeign(self::BILL_FOREIGN);
        });

        Schema::table('fin_donor_fund_transactions', function (Blueprint $table): void {
            $table->foreignId('site_id')
                ->nullable()
                ->after('fund_id')
                ->constrained('sites')
                ->restrictOnDelete();
            $table->foreignId('funding_stream_id')
                ->nullable()
                ->after('site_id')
                ->constrained('fin_funding_streams')
                ->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable()->after('funding_stream_id');
            $table->char('payload_hash', 64)->nullable()->after('idempotency_key');
            $table->foreignId('bank_account_id')
                ->nullable()
                ->after('bill_id')
                ->constrained('fin_bank_accounts')
                ->restrictOnDelete();
            $table->foreignId('expense_account_id')
                ->nullable()
                ->after('bank_account_id')
                ->constrained('fin_accounts')
                ->restrictOnDelete();
            $table->foreignId('reversal_of_transaction_id')
                ->nullable()
                ->after('expense_account_id')
                ->constrained('fin_donor_fund_transactions')
                ->restrictOnDelete();

            $table->unique('idempotency_key', 'fin_donor_fund_txn_request_unique');
            $table->unique('bill_id', 'fin_donor_fund_txn_bill_unique');
            $table->unique('journal_id', self::JOURNAL_UNIQUE);
            $table->unique('reversal_of_transaction_id', 'fin_donor_fund_txn_reversal_unique');
            $table->foreign('fund_id', self::FUND_FOREIGN)
                ->references('id')->on('fin_donor_funds')->restrictOnDelete();
            $table->foreign('journal_id', self::JOURNAL_FOREIGN)
                ->references('id')->on('fin_journals')->restrictOnDelete();
            $table->foreign('bill_id', self::BILL_FOREIGN)
                ->references('id')->on('fin_bills')->restrictOnDelete();
        });

        DB::table('fin_donor_fund_transactions as transaction')
            ->join('fin_donor_funds as fund', 'fund.id', '=', 'transaction.fund_id')
            ->whereNull('transaction.funding_stream_id')
            ->whereNotNull('fund.funding_stream_id')
            ->update([
                'transaction.funding_stream_id' => DB::raw('fund.funding_stream_id'),
            ]);
        DB::table('fin_donor_fund_transactions as transaction')
            ->join('fin_bills as bill', 'bill.id', '=', 'transaction.bill_id')
            ->whereNull('transaction.site_id')
            ->whereNotNull('bill.site_id')
            ->update([
                'transaction.site_id' => DB::raw('bill.site_id'),
            ]);

        $permission = Permission::query()->updateOrCreate(
            ['key' => self::GLOBAL_SITE_PERMISSION],
            [
                'description' => 'Manage donor-fund bill applications across all active Sites',
                'group' => 'finance',
                'module' => 'Finance',
            ],
        );
        Role::query()
            ->whereIn('name', ['admin', 'finance'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', self::GLOBAL_SITE_PERMISSION)->first();
        if ($permission) {
            Role::query()->each(
                fn (Role $role) => $role->permissions()->detach($permission->id),
            );
            $permission->delete();
        }

        Schema::table('fin_donor_fund_transactions', function (Blueprint $table): void {
            $table->dropForeign(self::FUND_FOREIGN);
            $table->dropForeign(self::JOURNAL_FOREIGN);
            $table->dropForeign(self::BILL_FOREIGN);
            $table->dropUnique('fin_donor_fund_txn_reversal_unique');
            $table->dropUnique(self::JOURNAL_UNIQUE);
            $table->dropUnique('fin_donor_fund_txn_bill_unique');
            $table->dropUnique('fin_donor_fund_txn_request_unique');
            $table->dropConstrainedForeignId('reversal_of_transaction_id');
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('funding_stream_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['payload_hash', 'idempotency_key']);
            $table->foreign('fund_id', self::FUND_FOREIGN)
                ->references('id')->on('fin_donor_funds')->cascadeOnDelete();
            $table->foreign('journal_id', self::JOURNAL_FOREIGN)
                ->references('id')->on('fin_journals')->nullOnDelete();
            $table->foreign('bill_id', self::BILL_FOREIGN)
                ->references('id')->on('fin_bills')->nullOnDelete();
        });
    }

    private function governExistingJournalLineage(): void
    {
        DB::table('fin_donor_fund_transactions')
            ->whereNotNull('journal_id')
            ->orderBy('id')
            ->chunkById(200, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $fund = DB::table('fin_donor_funds')
                        ->where('id', $transaction->fund_id)
                        ->first(['organization_id', 'gl_account_id', 'funding_stream_id']);
                    $journal = DB::table('fin_journals')
                        ->where('id', $transaction->journal_id)
                        ->first([
                            'organization_id',
                            'status',
                            'total_amount',
                            'source_type',
                            'source_id',
                            'reversal_of_journal_id',
                            'reversed_by_journal_id',
                        ]);
                    if ($fund === null || $journal === null) {
                        throw new RuntimeException(
                            'Donor-fund transaction migration stopped: a linked journal is missing.'
                        );
                    }

                    if ((int) $journal->organization_id !== (int) $fund->organization_id
                        || $journal->status !== 'posted'
                        || $journal->reversal_of_journal_id !== null
                        || $journal->reversed_by_journal_id !== null
                        || bccomp((string) $journal->total_amount, (string) $transaction->amount, 2) !== 0
                        || ! $this->journalMatchesApprovedPolicy($transaction, $fund)) {
                        throw new RuntimeException(
                            'Donor-fund transaction migration stopped: linked journal accounting requires finance review.'
                        );
                    }

                    if ($journal->source_type === null && $journal->source_id === null) {
                        continue;
                    }

                    if (ltrim((string) $journal->source_type, '\\') !== FinDonorFundTransaction::class
                        || (int) $journal->source_id !== (int) $transaction->id) {
                        throw new RuntimeException(
                            'Donor-fund transaction migration stopped: conflicting journal ownership requires finance review.'
                        );
                    }
                }
            });

        DB::table('fin_donor_fund_transactions')
            ->whereNotNull('journal_id')
            ->orderBy('id')
            ->chunkById(200, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    DB::table('fin_journals')
                        ->where('id', $transaction->journal_id)
                        ->update([
                            'source_type' => FinDonorFundTransaction::class,
                            'source_id' => $transaction->id,
                        ]);
                }
            });
    }

    private function journalMatchesApprovedPolicy(object $transaction, object $fund): bool
    {
        if ($fund->gl_account_id === null
            || ! DB::table('fin_accounts')
                ->where('id', $fund->gl_account_id)
                ->where('organization_id', $fund->organization_id)
                ->whereIn('type', ['liability', 'equity'])
                ->exists()
            || ! in_array($transaction->type, ['receipt', 'expenditure'], true)) {
            return false;
        }

        $lines = DB::table('fin_journal_lines')
            ->where('journal_id', $transaction->journal_id)
            ->get();
        if ($lines->count() !== 2) {
            return false;
        }

        $amount = (string) $transaction->amount;
        $totalDebits = $lines->reduce(
            fn (string $total, object $line): string => bcadd($total, (string) $line->debit, 2),
            '0.00',
        );
        $totalCredits = $lines->reduce(
            fn (string $total, object $line): string => bcadd($total, (string) $line->credit, 2),
            '0.00',
        );
        if (bccomp($totalDebits, $amount, 2) !== 0
            || bccomp($totalCredits, $amount, 2) !== 0) {
            return false;
        }

        if ($transaction->type === 'receipt') {
            $fundCredit = $lines->first(fn (object $line): bool => (int) $line->account_id === (int) $fund->gl_account_id
                && bccomp((string) $line->debit, '0.00', 2) === 0
                && bccomp((string) $line->credit, $amount, 2) === 0);
            $assetDebit = $lines->first(fn (object $line): bool => bccomp((string) $line->debit, $amount, 2) === 0
                && bccomp((string) $line->credit, '0.00', 2) === 0
                && DB::table('fin_accounts')
                    ->where('id', $line->account_id)
                    ->where('organization_id', $fund->organization_id)
                    ->where('type', 'asset')
                    ->exists());

            return $fundCredit !== null
                && $assetDebit !== null
                && $lines->every(fn (object $line): bool => $fund->funding_stream_id === null
                    ? $line->funding_stream_id === null
                    : (int) $line->funding_stream_id === (int) $fund->funding_stream_id);
        }

        $releaseAccountId = $fund->funding_stream_id === null
            ? null
            : DB::table('fin_funding_streams as stream')
                ->join('fin_accounts as account', 'account.id', '=', 'stream.default_revenue_account_id')
                ->where('stream.id', $fund->funding_stream_id)
                ->where('stream.organization_id', $fund->organization_id)
                ->where('account.organization_id', $fund->organization_id)
                ->where('account.type', 'revenue')
                ->value('account.id');
        $billSiteId = $transaction->bill_id === null
            ? null
            : DB::table('fin_bills')->where('id', $transaction->bill_id)->value('site_id');
        if ($releaseAccountId === null || $billSiteId === null) {
            return false;
        }

        $fundDebit = $lines->first(fn (object $line): bool => (int) $line->account_id === (int) $fund->gl_account_id
            && bccomp((string) $line->debit, $amount, 2) === 0
            && bccomp((string) $line->credit, '0.00', 2) === 0);
        $releaseCredit = $lines->first(fn (object $line): bool => (int) $line->account_id === (int) $releaseAccountId
            && bccomp((string) $line->debit, '0.00', 2) === 0
            && bccomp((string) $line->credit, $amount, 2) === 0);

        return $fundDebit !== null
            && $releaseCredit !== null
            && $lines->every(fn (object $line): bool => (int) $line->funding_stream_id === (int) $fund->funding_stream_id
                && (int) $line->site_id === (int) $billSiteId);
    }

    private function governExistingBillLineage(): void
    {
        DB::table('fin_donor_fund_transactions')
            ->whereNotNull('bill_id')
            ->orderBy('id')
            ->chunkById(200, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $fund = DB::table('fin_donor_funds')
                        ->where('id', $transaction->fund_id)
                        ->first(['organization_id', 'funding_stream_id']);
                    $bill = DB::table('fin_bills')
                        ->where('id', $transaction->bill_id)
                        ->first([
                            'id',
                            'organization_id',
                            'site_id',
                            'status',
                            'total_amount',
                            'journal_id',
                        ]);
                    $journal = $bill?->journal_id === null
                        ? null
                        : DB::table('fin_journals')->where('id', $bill->journal_id)->first();
                    $hasFundingConflict = $fund !== null
                        && $fund->funding_stream_id !== null
                        && DB::table('fin_bill_lines')
                            ->where('bill_id', $transaction->bill_id)
                            ->whereNotNull('funding_stream_id')
                            ->where('funding_stream_id', '!=', $fund->funding_stream_id)
                            ->exists();

                    if ($transaction->type !== 'expenditure'
                        || $fund === null
                        || $fund->funding_stream_id === null
                        || $bill === null
                        || (int) $bill->organization_id !== (int) $fund->organization_id
                        || $bill->site_id === null
                        || ! in_array($bill->status, ['approved', 'partially_paid', 'paid'], true)
                        || $journal === null
                        || (int) $journal->organization_id !== (int) $fund->organization_id
                        || $journal->status !== 'posted'
                        || ltrim((string) $journal->source_type, '\\') !== FinBill::class
                        || (int) $journal->source_id !== (int) $bill->id
                        || $journal->reversal_of_journal_id !== null
                        || $journal->reversed_by_journal_id !== null
                        || bccomp((string) $transaction->amount, (string) $bill->total_amount, 2) > 0
                        || $hasFundingConflict) {
                        throw new RuntimeException(
                            'Donor-fund transaction migration stopped: a bill application requires finance review before rollout.'
                        );
                    }
                }
            });
    }

    private function governExistingRollForward(): void
    {
        DB::table('fin_donor_funds')
            ->orderBy('id')
            ->chunkById(200, function ($funds): void {
                foreach ($funds as $fund) {
                    $transactions = DB::table('fin_donor_fund_transactions')
                        ->where('fund_id', $fund->id);
                    $unsupportedType = (clone $transactions)
                        ->whereNotIn('type', ['receipt', 'expenditure'])
                        ->exists();
                    $totals = (clone $transactions)
                        ->selectRaw("COALESCE(SUM(CASE WHEN type = 'receipt' THEN amount ELSE 0 END), 0) AS received")
                        ->selectRaw("COALESCE(SUM(CASE WHEN type = 'expenditure' THEN amount ELSE 0 END), 0) AS spent")
                        ->first();
                    $expectedAvailable = bcsub(
                        (string) $totals->received,
                        bcadd((string) $totals->spent, (string) $fund->total_committed, 2),
                        2,
                    );

                    if ($unsupportedType
                        || bccomp((string) $fund->total_received, (string) $totals->received, 2) !== 0
                        || bccomp((string) $fund->total_spent, (string) $totals->spent, 2) !== 0
                        || bccomp((string) $fund->available_balance, $expectedAvailable, 2) !== 0) {
                        throw new RuntimeException(
                            'Donor-fund transaction migration stopped: an aggregate roll-forward requires finance review before rollout.'
                        );
                    }
                }
            });
    }
};
