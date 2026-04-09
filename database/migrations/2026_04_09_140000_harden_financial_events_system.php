<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // 1. Harden fin_financial_events
        // ──────────────────────────────────────────────────────────
        Schema::table('fin_financial_events', function (Blueprint $table) {
            // Payment type determines credit account resolution
            $table->string('payment_type', 20)->default('ap')->after('currency');

            // Queue retry tracking
            $table->unsignedTinyInteger('retry_count')->default(0)->after('failure_reason');

            // Drop the old too-strict unique constraint (source_type + source_id + event_type)
            // This prevented legitimate re-postings when amounts change.
            $table->dropUnique('fin_events_source_unique');
        });

        // ──────────────────────────────────────────────────────────
        // 2. Leave provision tracking — stores last-posted liability
        //    per employee so monthly job posts only the DELTA
        // ──────────────────────────────────────────────────────────
        Schema::create('fin_leave_provision_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('leave_type', 30); // annual, sick, etc.
            $table->decimal('balance_hours', 10, 2);
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('provision_amount', 14, 2); // hours × rate
            $table->date('snapshot_date');
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'leave_type', 'snapshot_date'], 'fin_leave_prov_unique');
            $table->index(['organization_id', 'snapshot_date']);
        });

        // ──────────────────────────────────────────────────────────
        // 3. Add journal_id to timesheets for mileage posting
        // ──────────────────────────────────────────────────────────
        Schema::table('timesheets', function (Blueprint $table) {
            $table->foreignId('mileage_journal_id')->nullable()->after('reconciliation_findings')
                ->constrained('fin_journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mileage_journal_id');
        });

        Schema::dropIfExists('fin_leave_provision_snapshots');

        Schema::table('fin_financial_events', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'retry_count']);
            $table->unique(['source_type', 'source_id', 'event_type'], 'fin_events_source_unique');
        });
    }
};
