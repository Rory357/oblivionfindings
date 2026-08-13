<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts');
            $table->char('file_fingerprint', 64);
            $table->string('original_filename')->nullable();
            $table->string('format', 24)->default('csv');
            $table->string('status', 32)->default('processing');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['bank_account_id', 'file_fingerprint'], 'fin_bank_import_account_file_uq');
        });

        Schema::table('fin_bank_transactions', function (Blueprint $table): void {
            $table->foreignId('statement_import_id')->nullable()->after('bank_account_id')
                ->constrained('fin_bank_statement_imports')->nullOnDelete();
            $table->char('import_row_fingerprint', 64)->nullable()->after('statement_import_id');
            $table->unsignedInteger('import_row_number')->nullable()->after('import_row_fingerprint');
            $table->unique(['bank_account_id', 'import_row_fingerprint'], 'fin_bank_txn_account_row_uq');
        });

        Schema::table('fin_bank_reconciliations', function (Blueprint $table): void {
            $table->foreignId('statement_import_id')->nullable()->after('bank_account_id')
                ->constrained('fin_bank_statement_imports')->nullOnDelete();
            $table->foreignId('amends_reconciliation_id')->nullable()->after('statement_import_id')
                ->constrained('fin_bank_reconciliations')->nullOnDelete();
            $table->decimal('starting_balance', 14, 2)->nullable()->after('statement_balance');
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->string('integrity_state', 32)->default('verified')->after('version');
            $table->string('recovery_message')->nullable()->after('integrity_state');
            $table->index(['bank_account_id', 'statement_import_id'], 'fin_bank_recon_statement_idx');
        });

        Schema::table('fin_bank_reconciliation_lines', function (Blueprint $table): void {
            $table->foreignId('bank_account_id')->nullable()->after('reconciliation_id')
                ->constrained('fin_bank_accounts');
            $table->unsignedBigInteger('journal_id')->nullable()->after('journal_line_id');
            $table->unsignedBigInteger('adjustment_journal_id')->nullable()->after('journal_id');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('adjustment_journal_id');
            $table->unsignedBigInteger('active_bank_transaction_id')->nullable()->after('reversal_journal_id');
            $table->unsignedBigInteger('active_journal_line_id')->nullable()->after('active_bank_transaction_id');
            $table->foreignId('matched_by')->nullable()->after('is_matched')->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable()->after('matched_by');
            $table->foreignId('unmatched_by')->nullable()->after('matched_at')->constrained('users')->nullOnDelete();
            $table->timestamp('unmatched_at')->nullable()->after('unmatched_by');
            $table->unsignedInteger('aggregate_version')->nullable()->after('unmatched_at');
            $table->char('idempotency_key', 64)->nullable()->after('aggregate_version');
        });

        Schema::create('fin_bank_reconciliation_integrity_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->unsignedBigInteger('reconciliation_id')->nullable();
            $table->unsignedBigInteger('reconciliation_line_id')->nullable();
            $table->unsignedBigInteger('bank_transaction_id')->nullable();
            $table->string('issue_type', 64);
            $table->string('status', 24)->default('review_required');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index('organization_id', 'fin_bank_recon_review_org_idx');
            $table->index('bank_account_id', 'fin_bank_recon_review_account_idx');
            $table->index('reconciliation_id', 'fin_bank_recon_review_recon_idx');
            $table->index('reconciliation_line_id', 'fin_bank_recon_review_line_idx');
            $table->index('bank_transaction_id', 'fin_bank_recon_review_txn_idx');
        });

        Schema::create('fin_bank_reconciliation_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts');
            $table->foreignId('reconciliation_id')->constrained('fin_bank_reconciliations');
            $table->unsignedBigInteger('reconciliation_line_id')->nullable();
            $table->unsignedBigInteger('statement_import_id')->nullable();
            $table->unsignedBigInteger('bank_transaction_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 48);
            $table->unsignedInteger('aggregate_version');
            $table->char('idempotency_key', 64);
            $table->json('provenance')->nullable();
            $table->timestamp('occurred_at');

            $table->unique(['reconciliation_id', 'idempotency_key'], 'fin_bank_recon_event_idempotency_uq');
            $table->index(['reconciliation_id', 'occurred_at'], 'fin_bank_recon_event_timeline_idx');
        });

        $this->backfillLegacyRelationships();
        $this->backfillStartingBalances();

        Schema::table('fin_bank_reconciliation_lines', function (Blueprint $table): void {
            $table->foreign('journal_id', 'fin_bank_recon_line_journal_fk')->references('id')->on('fin_journals');
            $table->foreign('adjustment_journal_id', 'fin_bank_recon_line_adjustment_fk')->references('id')->on('fin_journals');
            $table->foreign('reversal_journal_id', 'fin_bank_recon_line_reversal_fk')->references('id')->on('fin_journals');
            $table->unique('active_bank_transaction_id', 'fin_bank_recon_active_txn_uq');
            $table->unique('active_journal_line_id', 'fin_bank_recon_active_journal_line_uq');
            $table->unique('reversal_journal_id', 'fin_bank_recon_reversal_journal_uq');
            $table->unique(['reconciliation_id', 'idempotency_key'], 'fin_bank_recon_line_idempotency_uq');
        });
    }

    private function backfillLegacyRelationships(): void
    {
        $activeTransactions = [];
        $activeJournalLines = [];

        DB::table('fin_bank_reconciliation_lines')->orderBy('id')->get()->each(function (object $line) use (&$activeTransactions, &$activeJournalLines): void {
            $reconciliation = DB::table('fin_bank_reconciliations')->where('id', $line->reconciliation_id)->first();
            $transaction = DB::table('fin_bank_transactions')->where('id', $line->bank_transaction_id)->first();
            $journalLine = $line->journal_line_id
                ? DB::table('fin_journal_lines')->where('id', $line->journal_line_id)->first()
                : null;
            $journal = $journalLine
                ? DB::table('fin_journals')->where('id', $journalLine->journal_id)->first()
                : null;
            $bankAccount = $reconciliation
                ? DB::table('fin_bank_accounts')->where('id', $reconciliation->bank_account_id)->first()
                : null;

            $issues = [];
            if (! $reconciliation || ! $transaction || ! $bankAccount) {
                $issues[] = 'missing_legacy_link';
            } elseif ((int) $transaction->bank_account_id !== (int) $reconciliation->bank_account_id) {
                $issues[] = 'cross_account_transaction';
            } elseif ((int) $transaction->organization_id !== (int) $reconciliation->organization_id) {
                $issues[] = 'cross_organization_transaction';
            }

            if ($line->is_matched && ! $journalLine) {
                $issues[] = 'missing_gl_link';
            } elseif ($journalLine && ! $journal) {
                $issues[] = 'missing_journal';
            }
            if ($journalLine && $bankAccount && (int) $journalLine->account_id !== (int) $bankAccount->gl_account_id) {
                $issues[] = 'non_bank_gl_line';
            }
            if ($journal && $reconciliation
                && ((int) $journal->organization_id !== (int) $reconciliation->organization_id
                    || $journal->status !== 'posted'
                    || $journal->reversed_by_journal_id !== null)) {
                $issues[] = 'unapplied_or_reversed_journal';
            }

            if ($line->is_matched && isset($activeTransactions[$line->bank_transaction_id])) {
                $issues[] = 'duplicate_active_transaction';
            }
            if ($line->is_matched && $line->journal_line_id && isset($activeJournalLines[$line->journal_line_id])) {
                $issues[] = 'duplicate_active_journal_line';
            }

            $validActive = (bool) $line->is_matched && $issues === [];
            if ($validActive) {
                $activeTransactions[$line->bank_transaction_id] = true;
                if ($line->journal_line_id) {
                    $activeJournalLines[$line->journal_line_id] = true;
                }
            }

            $legacyAdjustmentJournalId = $journal
                && $journal->source_type === \App\Domain\Finance\Models\FinBankTransaction::class
                && (int) $journal->source_id === (int) $line->bank_transaction_id
                && str_starts_with((string) $journal->description, 'Bank reconciliation adjustment:')
                    ? $journal->id
                    : null;

            DB::table('fin_bank_reconciliation_lines')->where('id', $line->id)->update([
                'bank_account_id' => $reconciliation?->bank_account_id,
                'journal_id' => $journalLine?->journal_id,
                'adjustment_journal_id' => $legacyAdjustmentJournalId,
                'active_bank_transaction_id' => $validActive ? $line->bank_transaction_id : null,
                'active_journal_line_id' => $validActive ? $line->journal_line_id : null,
                'matched_at' => $line->created_at,
                'aggregate_version' => 1,
                'idempotency_key' => hash('sha256', 'legacy-line:'.$line->id),
            ]);
            if ($legacyAdjustmentJournalId && $validActive) {
                DB::table('fin_journals')->where('id', $legacyAdjustmentJournalId)->update([
                    'source_type' => \App\Domain\Finance\Models\FinBankReconciliationLine::class,
                    'source_id' => $line->id,
                ]);
            }

            if ($issues !== []) {
                if ($reconciliation) {
                    DB::table('fin_bank_reconciliations')->where('id', $reconciliation->id)->update([
                        'integrity_state' => 'review_required',
                        'recovery_message' => 'Legacy reconciliation links require finance review before completion.',
                    ]);
                }

                DB::table('fin_bank_reconciliation_integrity_reviews')->insert([
                    'organization_id' => $reconciliation?->organization_id,
                    'bank_account_id' => $reconciliation?->bank_account_id,
                    'reconciliation_id' => $reconciliation?->id,
                    'reconciliation_line_id' => $line->id,
                    'bank_transaction_id' => $line->bank_transaction_id,
                    'issue_type' => $issues[0],
                    'details' => json_encode(['issues' => $issues], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        DB::table('fin_bank_transactions')
            ->where('source', 'import')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $row): string => implode('|', [
                $row->bank_account_id,
                $row->transaction_date,
                number_format((float) $row->amount, 2, '.', ''),
                mb_strtolower(trim((string) $row->description)),
                mb_strtolower(trim((string) $row->reference)),
            ]))
            ->filter(fn ($rows): bool => $rows->count() > 1)
            ->each(function ($rows): void {
                $first = $rows->first();
                DB::table('fin_bank_reconciliation_integrity_reviews')->insert([
                    'organization_id' => $first->organization_id,
                    'bank_account_id' => $first->bank_account_id,
                    'bank_transaction_id' => $first->id,
                    'issue_type' => 'ambiguous_legacy_import_duplicates',
                    'details' => json_encode(['bank_transaction_ids' => $rows->pluck('id')->values()->all()], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    private function backfillStartingBalances(): void
    {
        DB::table('fin_bank_accounts')->orderBy('id')->get()->each(function (object $account): void {
            $balance = (string) $account->opening_balance;

            DB::table('fin_bank_reconciliations')
                ->where('bank_account_id', $account->id)
                ->orderBy('statement_date')
                ->orderBy('id')
                ->get()
                ->each(function (object $reconciliation) use (&$balance): void {
                    DB::table('fin_bank_reconciliations')->where('id', $reconciliation->id)->update([
                        'starting_balance' => $balance,
                    ]);

                    if ($reconciliation->status === 'completed') {
                        $balance = (string) $reconciliation->statement_balance;
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_reconciliation_events');
        Schema::dropIfExists('fin_bank_reconciliation_integrity_reviews');

        Schema::table('fin_bank_reconciliation_lines', function (Blueprint $table): void {
            $table->dropForeign('fin_bank_recon_line_reversal_fk');
            $table->dropForeign('fin_bank_recon_line_adjustment_fk');
            $table->dropForeign('fin_bank_recon_line_journal_fk');
            $table->dropUnique('fin_bank_recon_line_idempotency_uq');
            $table->dropUnique('fin_bank_recon_reversal_journal_uq');
            $table->dropUnique('fin_bank_recon_active_journal_line_uq');
            $table->dropUnique('fin_bank_recon_active_txn_uq');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('matched_by');
            $table->dropConstrainedForeignId('unmatched_by');
            $table->dropColumn([
                'journal_id', 'adjustment_journal_id', 'reversal_journal_id',
                'active_bank_transaction_id', 'active_journal_line_id', 'matched_at',
                'unmatched_at', 'aggregate_version', 'idempotency_key',
            ]);
        });

        Schema::table('fin_bank_reconciliations', function (Blueprint $table): void {
            $table->dropIndex('fin_bank_recon_statement_idx');
            $table->dropConstrainedForeignId('amends_reconciliation_id');
            $table->dropConstrainedForeignId('statement_import_id');
            $table->dropColumn(['starting_balance', 'version', 'integrity_state', 'recovery_message']);
        });

        Schema::table('fin_bank_transactions', function (Blueprint $table): void {
            $table->dropUnique('fin_bank_txn_account_row_uq');
            $table->dropConstrainedForeignId('statement_import_id');
            $table->dropColumn(['import_row_fingerprint', 'import_row_number']);
        });

        Schema::dropIfExists('fin_bank_statement_imports');
    }
};
