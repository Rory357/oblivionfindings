<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add immutable, future-facing settlement provenance without rewriting legacy
 * allocation history. Existing rows deliberately remain review_required with
 * nullable canonical keys; only the canonical services populate the new keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_payment_allocations', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('sites')
                ->restrictOnDelete();
            $table->foreignId('settlement_journal_id')
                ->nullable()
                ->after('journal_id')
                ->constrained('fin_journals')
                ->restrictOnDelete();
            $table->foreignId('bank_transaction_id')
                ->nullable()
                ->after('settlement_journal_id')
                ->constrained('fin_bank_transactions')
                ->restrictOnDelete();
            $table->string('settlement_source_key')
                ->nullable()
                ->after('source_id');
            $table->string('integrity_state', 32)
                ->default('review_required')
                ->after('settlement_source_key');

            $table->unique(
                'settlement_source_key',
                'fin_payment_allocations_settlement_source_unique',
            );
            $table->unique(
                'bank_transaction_id',
                'fin_payment_allocations_bank_transaction_unique',
            );
            $table->index(
                ['organization_id', 'site_id', 'type'],
                'fin_payment_allocations_org_site_type_index',
            );
            $table->index(
                ['organization_id', 'integrity_state'],
                'fin_payment_allocations_org_integrity_index',
            );
        });

        Schema::table('fin_payment_matches', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('sites')
                ->restrictOnDelete();
            $table->string('suggestion_key')
                ->nullable()
                ->after('matchable_id');
            $table->foreignId('rejected_by')
                ->nullable()
                ->after('confirmed_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('rejected_at')
                ->nullable()
                ->after('rejected_by');
            $table->string('rejection_reason', 500)
                ->nullable()
                ->after('rejected_at');

            $table->unique(
                'suggestion_key',
                'fin_payment_matches_suggestion_unique',
            );
            $table->index(
                ['organization_id', 'site_id', 'status'],
                'fin_payment_matches_org_site_status_index',
            );
        });

        Schema::table('fin_payment_run_items', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('payment_run_id')
                ->constrained('sites')
                ->restrictOnDelete();
            $table->foreignId('settlement_bill_id')
                ->nullable()
                ->after('bill_id')
                ->constrained('fin_bills')
                ->restrictOnDelete();

            $table->unique(
                'settlement_bill_id',
                'fin_payment_run_items_settlement_bill_unique',
            );
            $table->index(
                ['payment_run_id', 'site_id'],
                'fin_payment_run_items_run_site_index',
            );
        });

        Schema::create('fin_manual_receipt_idempotencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key');
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('invoice_id')
                ->constrained('fin_invoices')
                ->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->foreignId('allocation_id')
                ->nullable()
                ->constrained('fin_payment_allocations')
                ->restrictOnDelete();
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                'idempotency_key',
                'fin_manual_receipt_key_unique',
            );
            $table->unique(
                'allocation_id',
                'fin_manual_receipt_allocation_unique',
            );
            $table->index(
                ['organization_id', 'invoice_id'],
                'fin_manual_receipt_org_invoice_index',
            );
        });

        $viewPermission = Permission::query()->updateOrCreate(
            ['key' => 'finance.payments.viewAllSites'],
            [
                'description' => 'View payment allocation history across all active Sites',
                'group' => 'finance',
                'module' => 'Finance',
            ],
        );
        $managePermission = Permission::query()->updateOrCreate(
            ['key' => 'finance.payments.manageAllSites'],
            [
                'description' => 'Manage payment settlement across all active Sites',
                'group' => 'finance',
                'module' => 'Finance',
            ],
        );

        Role::query()
            ->whereIn('name', ['admin', 'finance'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([
                $viewPermission->id,
                $managePermission->id,
            ]));
        Role::query()
            ->where('name', 'auditor')
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$viewPermission->id]));
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_manual_receipt_idempotencies');

        $permissionIds = Permission::query()
            ->whereIn('key', [
                'finance.payments.viewAllSites',
                'finance.payments.manageAllSites',
            ])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            Role::query()->each(
                fn (Role $role) => $role->permissions()->detach($permissionIds->all()),
            );
            Permission::query()->whereKey($permissionIds)->delete();
        }

        Schema::table('fin_payment_run_items', function (Blueprint $table) {
            $table->dropForeign(['settlement_bill_id']);
            $table->dropForeign(['site_id']);
            $table->dropUnique('fin_payment_run_items_settlement_bill_unique');
            $table->dropIndex('fin_payment_run_items_run_site_index');
            $table->dropColumn(['settlement_bill_id', 'site_id']);
        });

        Schema::table('fin_payment_matches', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropUnique('fin_payment_matches_suggestion_unique');
            $table->dropIndex('fin_payment_matches_org_site_status_index');
            $table->dropForeign(['site_id']);
            $table->dropColumn([
                'rejection_reason',
                'rejected_at',
                'rejected_by',
                'suggestion_key',
                'site_id',
            ]);
        });

        Schema::table('fin_payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['bank_transaction_id']);
            $table->dropForeign(['settlement_journal_id']);
            $table->dropForeign(['site_id']);
            $table->dropUnique('fin_payment_allocations_settlement_source_unique');
            $table->dropUnique('fin_payment_allocations_bank_transaction_unique');
            $table->dropIndex('fin_payment_allocations_org_site_type_index');
            $table->dropIndex('fin_payment_allocations_org_integrity_index');
            $table->dropColumn([
                'bank_transaction_id',
                'settlement_journal_id',
                'site_id',
                'settlement_source_key',
                'integrity_state',
            ]);
        });
    }
};
