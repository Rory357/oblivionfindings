<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured break-glass fields. The redesigned Emergency-access page captures a
 * structured reason category, the authorisation mode (self / co-sign) with the
 * co-signer, the operator's attestations at grant time, and a post-event review
 * (justified / not justified). Previously only a free-text reason + expiry were
 * stored, so the card "co-signed by" line, the audit "review" state and the
 * Review modal had no source. All columns are nullable / default-off so legacy
 * rows and the legacy request page keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_break_glass_accesses', function (Blueprint $table) {
            // Grant-time structure
            if (! Schema::hasColumn('client_break_glass_accesses', 'reason_category')) {
                $table->string('reason_category')->nullable()->after('reason');
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'authorization_mode')) {
                $table->string('authorization_mode')->nullable()->after('reason_category'); // self | co_sign
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'co_signed_by')) {
                $table->foreignId('co_signed_by')->nullable()->after('authorization_mode')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'acknowledged_min_necessary')) {
                $table->boolean('acknowledged_min_necessary')->default(false)->after('co_signed_by');
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'acknowledged_incident_report')) {
                $table->boolean('acknowledged_incident_report')->default(false)->after('acknowledged_min_necessary');
            }

            // Post-event review
            if (! Schema::hasColumn('client_break_glass_accesses', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('expires_at');
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'review_outcome')) {
                $table->string('review_outcome')->nullable()->after('reviewed_by'); // justified | not_justified
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'review_notes')) {
                $table->text('review_notes')->nullable()->after('review_outcome');
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'incident_report_linked')) {
                $table->boolean('incident_report_linked')->default(false)->after('review_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_break_glass_accesses', function (Blueprint $table) {
            foreach (['co_signed_by', 'reviewed_by'] as $fk) {
                if (Schema::hasColumn('client_break_glass_accesses', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach ([
                'reason_category', 'authorization_mode', 'acknowledged_min_necessary',
                'acknowledged_incident_report', 'reviewed_at', 'review_outcome',
                'review_notes', 'incident_report_linked',
            ] as $col) {
                if (Schema::hasColumn('client_break_glass_accesses', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
