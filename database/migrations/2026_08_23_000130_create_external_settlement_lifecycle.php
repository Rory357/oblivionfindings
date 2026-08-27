<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_BILL_UNIQUE = 'fin_payment_run_items_active_bill_unique';

    public function up(): void
    {
        $legacyActiveDuplicates = DB::table('fin_payment_run_items')
            ->join('fin_payment_runs', 'fin_payment_runs.id', '=', 'fin_payment_run_items.payment_run_id')
            ->whereIn('fin_payment_runs.status', ['draft', 'approved', 'processing'])
            ->whereNotNull('fin_payment_run_items.settlement_bill_id')
            ->groupBy('fin_payment_run_items.settlement_bill_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('fin_payment_run_items.settlement_bill_id');
        if ($legacyActiveDuplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add active payment-run membership until duplicate active bill IDs are adjudicated: '
                .$legacyActiveDuplicates->sort()->implode(', ').'.'
            );
        }

        Schema::create('fin_external_settlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('source_type', 160);
            $table->unsignedBigInteger('source_id');
            $table->string('purpose', 64);
            $table->unsignedInteger('attempt_number')->default(1);
            $table->char('active_source_key', 64)->nullable();
            $table->string('status', 32)->default('prepared');
            $table->char('replay_key', 64);
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('artifact_disk', 32);
            $table->string('artifact_path');
            $table->char('artifact_sha256', 64);
            $table->timestamp('prepared_at');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('export_replay_key', 64)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('acceptance_reference')->nullable();
            $table->json('acceptance_evidence')->nullable();
            $table->char('acceptance_replay_key', 64)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('rejection_reference')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->json('rejection_evidence')->nullable();
            $table->char('rejection_replay_key', 64)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->restrictOnDelete();
            $table->char('settlement_replay_key', 64)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reconciled_bank_transaction_id')->nullable()->constrained('fin_bank_transactions')->restrictOnDelete();
            $table->string('reconciliation_reference')->nullable();
            $table->json('reconciliation_evidence')->nullable();
            $table->char('reconciliation_replay_key', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'purpose', 'attempt_number'],
                'fin_ext_settlements_source_attempt_unique',
            );
            $table->unique('active_source_key', 'fin_ext_settlements_active_source_unique');
            $table->unique(['organization_id', 'replay_key'], 'fin_ext_settlements_replay_unique');
            $table->unique('journal_id', 'fin_ext_settlements_journal_unique');
            $table->unique('reconciled_bank_transaction_id', 'fin_ext_settlements_bank_txn_unique');
            $table->index(['organization_id', 'status'], 'fin_ext_settlements_org_status_index');
        });

        Schema::create('fin_external_settlement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_settlement_id')->constrained('fin_external_settlements')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->char('replay_key', 64);
            $table->json('evidence')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['external_settlement_id', 'event_type'], 'fin_ext_settlement_events_type_unique');
            $table->unique('replay_key', 'fin_ext_settlement_events_replay_unique');
        });

        Schema::table('fin_payment_runs', function (Blueprint $table): void {
            $table->string('status', 32)->default('draft')->change();
        });

        Schema::table('fin_payment_run_items', function (Blueprint $table): void {
            $table->foreignId('active_settlement_bill_id')
                ->nullable()
                ->after('settlement_bill_id')
                ->constrained('fin_bills')
                ->restrictOnDelete();
            $table->unique('active_settlement_bill_id', self::ACTIVE_BILL_UNIQUE);
        });

        DB::table('fin_payment_run_items')
            ->join('fin_payment_runs', 'fin_payment_runs.id', '=', 'fin_payment_run_items.payment_run_id')
            ->whereIn('fin_payment_runs.status', ['draft', 'approved', 'processing'])
            ->whereNotNull('fin_payment_run_items.settlement_bill_id')
            ->update([
                'fin_payment_run_items.active_settlement_bill_id' => DB::raw('fin_payment_run_items.settlement_bill_id'),
            ]);

        Schema::table('fin_payment_run_items', function (Blueprint $table): void {
            $table->index('settlement_bill_id', 'fin_payment_run_items_settlement_bill_index');
        });
        Schema::table('fin_payment_run_items', function (Blueprint $table): void {
            // The settlement_bill_id foreign key needs a supporting index.
            // Create its replacement before dropping the legacy unique index.
            $table->dropUnique('fin_payment_run_items_settlement_bill_unique');
        });
    }

    public function down(): void
    {
        $historicalReuse = DB::table('fin_payment_run_items')
            ->whereNotNull('settlement_bill_id')
            ->groupBy('settlement_bill_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('settlement_bill_id');
        if ($historicalReuse->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot restore lifetime payment-run membership uniqueness after corrected-run reuse for bill IDs: '
                .$historicalReuse->sort()->implode(', ').'.'
            );
        }
        $nonLegacyStatuses = DB::table('fin_payment_runs')
            ->whereNotIn('status', ['draft', 'approved', 'processing', 'completed', 'cancelled'])
            ->distinct()
            ->orderBy('status')
            ->pluck('status');
        if ($nonLegacyStatuses->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot restore the legacy payment-run status enum while lifecycle rows use: '
                .$nonLegacyStatuses->implode(', ').'.'
            );
        }

        Schema::table('fin_payment_run_items', function (Blueprint $table): void {
            $table->unique('settlement_bill_id', 'fin_payment_run_items_settlement_bill_unique');
        });
        Schema::table('fin_payment_run_items', function (Blueprint $table): void {
            // Keep a supporting index in place until the restored unique index
            // exists, otherwise MySQL refuses to drop the non-unique index.
            $table->dropIndex('fin_payment_run_items_settlement_bill_index');
            // MySQL may use the unique index to support this foreign key. Drop
            // the dependent constraint before removing its backing index.
            $table->dropForeign(['active_settlement_bill_id']);
            $table->dropUnique(self::ACTIVE_BILL_UNIQUE);
            $table->dropColumn('active_settlement_bill_id');
        });

        Schema::dropIfExists('fin_external_settlement_events');
        Schema::dropIfExists('fin_external_settlements');

        Schema::table('fin_payment_runs', function (Blueprint $table): void {
            $table->enum('status', ['draft', 'approved', 'processing', 'completed', 'cancelled'])
                ->default('draft')
                ->change();
        });
    }
};
