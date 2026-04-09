<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // 1. Financial Events — audit trail + idempotency guard
        // ──────────────────────────────────────────────────────────
        Schema::create('fin_financial_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();

            // Polymorphic source (FleetFuelLog, FleetWorkOrder, etc.)
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');

            // Classification
            $table->string('event_type', 50); // fuel_expense, maintenance_expense, training_cost, etc.
            $table->text('description')->nullable();

            // Financial
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('NZD');

            // GL mapping
            $table->foreignId('debit_account_id')->constrained('fin_accounts');
            $table->foreignId('credit_account_id')->constrained('fin_accounts');

            // Allocation dimensions (all nullable — populated where applicable)
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('funding_stream_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();

            // Posting
            $table->date('event_date');
            $table->enum('status', ['pending', 'posted', 'failed', 'reversed'])->default('pending');
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->datetime('posted_at')->nullable();
            $table->text('failure_reason')->nullable();

            // Idempotency
            $table->string('idempotency_key')->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Prevent duplicate postings for same source
            $table->unique(['source_type', 'source_id', 'event_type'], 'fin_events_source_unique');
            $table->index(['organization_id', 'event_type', 'event_date']);
            $table->index(['organization_id', 'status']);
            $table->index(['site_id', 'event_date']);
            $table->index(['client_id', 'event_date']);
            $table->index(['asset_id', 'event_date']);
        });

        // ──────────────────────────────────────────────────────────
        // 2. Cost Allocations — cross-module query layer
        // ──────────────────────────────────────────────────────────
        Schema::create('fin_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('fin_journals')->cascadeOnDelete();
            $table->foreignId('journal_line_id')->constrained('fin_journal_lines')->cascadeOnDelete();
            $table->foreignId('financial_event_id')->nullable()->constrained('fin_financial_events')->nullOnDelete();

            // Allocation dimensions
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();

            $table->decimal('amount', 14, 2);
            $table->string('event_type', 50)->nullable();
            $table->date('event_date');
            $table->timestamps();

            $table->index(['site_id', 'event_date']);
            $table->index(['client_id', 'event_date']);
            $table->index(['staff_id', 'event_date']);
            $table->index(['asset_id', 'event_date']);
            $table->index(['event_type', 'event_date']);
            $table->index(['journal_id']);
        });

        // ──────────────────────────────────────────────────────────
        // 3. Add journal_id to operational tables for back-reference
        // ──────────────────────────────────────────────────────────
        Schema::table('fleet_fuel_logs', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('notes')->constrained('fin_journals')->nullOnDelete();
        });

        Schema::table('fleet_work_orders', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('completion_notes')->constrained('fin_journals')->nullOnDelete();
        });

        Schema::table('asset_maintenance_logs', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('notes')->constrained('fin_journals')->nullOnDelete();
        });

        Schema::table('hr_expense_claims', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('notes')->constrained('fin_journals')->nullOnDelete();
        });

        Schema::table('hr_course_enrollments', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('notes')->constrained('fin_journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_course_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });

        Schema::table('hr_expense_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });

        Schema::table('asset_maintenance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });

        Schema::table('fleet_work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });

        Schema::table('fleet_fuel_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });

        Schema::dropIfExists('fin_cost_allocations');
        Schema::dropIfExists('fin_financial_events');
    }
};
