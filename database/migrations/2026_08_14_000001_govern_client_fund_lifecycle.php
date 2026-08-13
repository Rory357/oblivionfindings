<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_funds', function (Blueprint $table): void {
            $table->char('currency_code', 3)->default('NZD')->after('fund_type');
            $table->decimal('available_balance', 12, 2)->default(0)->after('balance');
            $table->string('overdraft_policy_state', 32)->default('prohibited')->after('available_balance');
            $table->decimal('overdraft_limit', 12, 2)->default(0)->after('overdraft_policy_state');
            $table->foreignId('overdraft_authorized_by')->nullable()->after('overdraft_limit')->constrained('users')->nullOnDelete();
            $table->timestamp('overdraft_authorized_at')->nullable()->after('overdraft_authorized_by');
            $table->text('overdraft_authorization_reason')->nullable()->after('overdraft_authorized_at');
            $table->string('governance_review_status', 32)->default('clear')->after('overdraft_authorization_reason');
            $table->text('governance_review_reason')->nullable()->after('governance_review_status');
            $table->string('reconciliation_status', 32)->default('clear')->after('governance_review_reason');
            $table->decimal('reconciliation_difference', 12, 2)->default(0)->after('reconciliation_status');
            $table->json('reconciliation_details')->nullable()->after('reconciliation_difference');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_details');
        });

        Schema::table('client_fund_transactions', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('client_fund_id')->constrained('clients')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->after('client_id')->constrained('sites')->nullOnDelete();
            $table->foreignId('destination_fund_id')->nullable()->after('site_id')->constrained('client_funds')->nullOnDelete();
            $table->foreignId('counterpart_transaction_id')->nullable()->after('destination_fund_id')->constrained('client_fund_transactions')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->after('counterpart_transaction_id')->constrained('client_fund_transactions')->restrictOnDelete();
            $table->string('status', 24)->default('pending')->after('idempotency_key');
            $table->char('currency_code', 3)->default('NZD')->after('amount');
            $table->string('source_type', 80)->default('manual')->after('reference');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->boolean('approval_required')->default(false)->after('recorded_by');
            $table->timestamp('requested_at')->nullable()->after('approval_required');
            $table->foreignId('approved_by')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_reason')->nullable()->after('approved_at');
            $table->foreignId('rejected_by')->nullable()->after('approval_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->text('reversal_reason')->nullable()->after('rejection_reason');
            $table->timestamp('balance_effect_applied_at')->nullable()->after('reversal_reason');
            $table->timestamp('posting_attempted_at')->nullable()->after('gl_posted_at');
            $table->timestamp('posting_failed_at')->nullable()->after('posting_attempted_at');
            $table->string('posting_failure_code', 120)->nullable()->after('posting_failed_at');
            $table->text('posting_failure_message')->nullable()->after('posting_failure_code');

            $table->unique('reversal_of_id', 'client_fund_transactions_reversal_once_unique');
            $table->index(['client_id', 'status'], 'client_fund_transactions_client_status_index');
            $table->index(['site_id', 'status'], 'client_fund_transactions_site_status_index');
            $table->index(['status', 'journal_id'], 'client_fund_transactions_status_journal_index');
        });

        Schema::table('fin_journal_lines', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('funding_stream_id')->constrained('clients')->nullOnDelete();
            $table->foreignId('client_fund_id')->nullable()->after('client_id')->constrained('client_funds')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->after('client_fund_id')->constrained('sites')->nullOnDelete();
            $table->index(['client_id', 'client_fund_id', 'account_id'], 'fin_journal_lines_client_fund_dimension_index');
        });

        $this->backfillLegacyFunds();
    }

    private function backfillLegacyFunds(): void
    {
        DB::table('client_funds')
            ->orderBy('id')
            ->chunkById(200, function ($funds): void {
                foreach ($funds as $fund) {
                    $balance = bcadd((string) $fund->balance, '0', 2);
                    $hasTransactions = DB::table('client_fund_transactions')
                        ->where('client_fund_id', $fund->id)
                        ->exists();
                    $requiresReview = $hasTransactions || bccomp($balance, '0.00', 2) !== 0;

                    DB::table('client_funds')->where('id', $fund->id)->update([
                        'currency_code' => 'NZD',
                        'available_balance' => bccomp($balance, '0.00', 2) < 0 ? '0.00' : $balance,
                        'governance_review_status' => $requiresReview ? 'review_required' : 'clear',
                        'governance_review_reason' => $requiresReview
                            ? 'Legacy client-money balance or movement requires documented governance review.'
                            : null,
                        'reconciliation_status' => $requiresReview ? 'review' : 'clear',
                        'reconciliation_difference' => '0.00',
                    ]);

                    $client = DB::table('clients')->where('id', $fund->client_id)->first(['id', 'site_id']);

                    DB::table('client_fund_transactions')
                        ->where('client_fund_id', $fund->id)
                        ->update([
                            'client_id' => $client?->id,
                            'site_id' => $client?->site_id,
                            'status' => 'review',
                            'currency_code' => 'NZD',
                            'source_type' => 'legacy',
                            'approval_required' => true,
                            'requested_at' => DB::raw('created_at'),
                            'balance_effect_applied_at' => DB::raw('created_at'),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('fin_journal_lines', function (Blueprint $table): void {
            $table->dropIndex('fin_journal_lines_client_fund_dimension_index');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('client_fund_id');
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('client_fund_transactions', function (Blueprint $table): void {
            $table->dropIndex('client_fund_transactions_status_journal_index');
            $table->dropIndex('client_fund_transactions_site_status_index');
            $table->dropIndex('client_fund_transactions_client_status_index');
            $table->dropUnique('client_fund_transactions_reversal_once_unique');
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('counterpart_transaction_id');
            $table->dropConstrainedForeignId('destination_fund_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn([
                'status',
                'currency_code',
                'source_type',
                'source_id',
                'approval_required',
                'requested_at',
                'approved_at',
                'approval_reason',
                'rejected_at',
                'rejection_reason',
                'reversal_reason',
                'balance_effect_applied_at',
                'posting_attempted_at',
                'posting_failed_at',
                'posting_failure_code',
                'posting_failure_message',
            ]);
        });

        Schema::table('client_funds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('overdraft_authorized_by');
            $table->dropColumn([
                'currency_code',
                'available_balance',
                'overdraft_policy_state',
                'overdraft_limit',
                'overdraft_authorized_at',
                'overdraft_authorization_reason',
                'governance_review_status',
                'governance_review_reason',
                'reconciliation_status',
                'reconciliation_difference',
                'reconciliation_details',
                'reconciled_at',
            ]);
        });
    }
};
