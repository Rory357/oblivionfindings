<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────────────────
        // Spend Approvals — governance sign-off for spend above a configured
        // threshold (capex, large opex, supplier contracts, donor-restricted).
        // Polymorphic source — can attach to FinBill, FinPurchaseOrder,
        // FinPaymentRun, or be a stand-alone "future commitment" approval.
        // ──────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('spend_approvals')) {
            Schema::create('spend_approvals', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique(); // e.g. SA-2026-0001
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('category'); // capex, opex, supplier_contract, donor_restricted
                $table->decimal('amount', 14, 2);
                $table->string('currency', 3)->default('NZD');

                // Polymorphic source (nullable — stand-alone future commitments are OK)
                $table->nullableMorphs('source'); // source_type / source_id

                // Optional foreign keys to operational entities
                $table->foreignId('site_id')->nullable();
                $table->foreignId('cost_centre_id')->nullable();
                $table->foreignId('funding_stream_id')->nullable();
                $table->foreignId('donor_fund_id')->nullable();
                $table->foreignId('budget_id')->nullable();
                $table->foreignId('budget_line_item_id')->nullable();

                // Workflow
                $table->string('status')->default('draft'); // draft, submitted, approved, rejected, expired
                $table->foreignId('requested_by')->constrained('users');
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users');
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_notes')->nullable();

                // Governance link
                $table->foreignId('resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
                $table->boolean('requires_board')->default(false);

                $table->json('attachments')->nullable();
                $table->date('valid_until')->nullable(); // expires if not used
                $table->softDeletes();
                $table->timestamps();

                $table->index(['status', 'category']);
                $table->index('decided_at');
            });
        }

        // ──────────────────────────────────────────────────────────────────────
        // Budget Allocations — links annual board-approved Budget to monthly
        // operational SiteBudgetLine entries. One Budget has many Allocations
        // distributing its total across sites/periods/categories.
        // ──────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('budget_allocations')) {
            Schema::create('budget_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
                $table->foreignId('budget_line_item_id')->nullable()->constrained('budget_line_items')->nullOnDelete();
                $table->foreignId('site_id')->nullable();
                $table->foreignId('site_budget_line_id')->nullable(); // operational mapping (Finance side)
                $table->string('period_year_month', 7); // YYYY-MM
                $table->string('category')->nullable(); // payroll, rent, utilities, ...
                $table->decimal('allocated_amount', 14, 2);
                $table->decimal('forecast_amount', 14, 2)->nullable();
                $table->decimal('actual_amount', 14, 2)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();

                $table->index(['budget_id', 'period_year_month']);
                $table->index(['site_id', 'period_year_month']);
            });
        }

        // ──────────────────────────────────────────────────────────────────────
        // Cross-module FKs (added nullably so the link is documented but no
        // existing data is invalidated).
        // ──────────────────────────────────────────────────────────────────────
        if (Schema::hasTable('roadmap_initiatives') && ! Schema::hasColumn('roadmap_initiatives', 'strategic_goal_id')) {
            Schema::table('roadmap_initiatives', function (Blueprint $table) {
                $table->foreignId('strategic_goal_id')->nullable()->after('id')
                    ->constrained('strategic_goals')->nullOnDelete();
                $table->index('strategic_goal_id');
            });
        }

        if (Schema::hasTable('compliance_obligations') && ! Schema::hasColumn('compliance_obligations', 'workforce_requirement_id')) {
            Schema::table('compliance_obligations', function (Blueprint $table) {
                $table->foreignId('workforce_requirement_id')->nullable()->after('id');
                $table->index('workforce_requirement_id');
            });
        }

        // FinBill + FinPurchaseOrder spend approval link (nullable, advisory)
        if (Schema::hasTable('fin_bills') && ! Schema::hasColumn('fin_bills', 'spend_approval_id')) {
            Schema::table('fin_bills', function (Blueprint $table) {
                $table->foreignId('spend_approval_id')->nullable()->after('id')
                    ->constrained('spend_approvals')->nullOnDelete();
                $table->index('spend_approval_id');
            });
        }

        if (Schema::hasTable('fin_purchase_orders') && ! Schema::hasColumn('fin_purchase_orders', 'spend_approval_id')) {
            Schema::table('fin_purchase_orders', function (Blueprint $table) {
                $table->foreignId('spend_approval_id')->nullable()->after('id')
                    ->constrained('spend_approvals')->nullOnDelete();
                $table->index('spend_approval_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fin_purchase_orders') && Schema::hasColumn('fin_purchase_orders', 'spend_approval_id')) {
            Schema::table('fin_purchase_orders', function (Blueprint $table) {
                $table->dropForeign(['spend_approval_id']);
                $table->dropColumn('spend_approval_id');
            });
        }

        if (Schema::hasTable('fin_bills') && Schema::hasColumn('fin_bills', 'spend_approval_id')) {
            Schema::table('fin_bills', function (Blueprint $table) {
                $table->dropForeign(['spend_approval_id']);
                $table->dropColumn('spend_approval_id');
            });
        }

        if (Schema::hasTable('compliance_obligations') && Schema::hasColumn('compliance_obligations', 'workforce_requirement_id')) {
            Schema::table('compliance_obligations', function (Blueprint $table) {
                $table->dropColumn('workforce_requirement_id');
            });
        }

        if (Schema::hasTable('roadmap_initiatives') && Schema::hasColumn('roadmap_initiatives', 'strategic_goal_id')) {
            Schema::table('roadmap_initiatives', function (Blueprint $table) {
                $table->dropForeign(['strategic_goal_id']);
                $table->dropColumn('strategic_goal_id');
            });
        }

        Schema::dropIfExists('budget_allocations');
        Schema::dropIfExists('spend_approvals');
    }
};
