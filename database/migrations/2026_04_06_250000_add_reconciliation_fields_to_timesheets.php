<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (! Schema::hasColumn('timesheets', 'reconciliation_status')) {
                $table->string('reconciliation_status')->nullable()->after('payroll_segments_exported');
            }

            if (! Schema::hasColumn('timesheets', 'reconciliation_severity')) {
                $table->string('reconciliation_severity')->nullable()->after('reconciliation_status');
            }

            if (! Schema::hasColumn('timesheets', 'reconciliation_detected_at')) {
                $table->dateTime('reconciliation_detected_at')->nullable()->after('reconciliation_severity');
            }

            if (! Schema::hasColumn('timesheets', 'reconciliation_summary')) {
                $table->text('reconciliation_summary')->nullable()->after('reconciliation_detected_at');
            }

            if (! Schema::hasColumn('timesheets', 'reconciliation_findings')) {
                $table->json('reconciliation_findings')->nullable()->after('reconciliation_summary');
            }
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->index(['reconciliation_status', 'reconciliation_severity'], 'timesheets_reconciliation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropIndex('timesheets_reconciliation_status_idx');

            if (Schema::hasColumn('timesheets', 'reconciliation_findings')) {
                $table->dropColumn('reconciliation_findings');
            }

            if (Schema::hasColumn('timesheets', 'reconciliation_summary')) {
                $table->dropColumn('reconciliation_summary');
            }

            if (Schema::hasColumn('timesheets', 'reconciliation_detected_at')) {
                $table->dropColumn('reconciliation_detected_at');
            }

            if (Schema::hasColumn('timesheets', 'reconciliation_severity')) {
                $table->dropColumn('reconciliation_severity');
            }

            if (Schema::hasColumn('timesheets', 'reconciliation_status')) {
                $table->dropColumn('reconciliation_status');
            }
        });
    }
};
