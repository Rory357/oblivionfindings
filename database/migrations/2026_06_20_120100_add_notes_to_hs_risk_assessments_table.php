<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risk Assessments redesign — persist the optional notes captured by the
 * "Approve & activate" and "Record review / residual" wizard modals so those
 * fields are real (not dead UI). Additive, nullable; no behavioural change to
 * the existing service which simply leaves them null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hs_risk_assessments')) {
            return;
        }

        Schema::table('hs_risk_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('hs_risk_assessments', 'approval_note')) {
                $table->text('approval_note')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('hs_risk_assessments', 'last_review_note')) {
                $table->text('last_review_note')->nullable()->after('review_frequency_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hs_risk_assessments', function (Blueprint $table) {
            foreach (['approval_note', 'last_review_note'] as $column) {
                if (Schema::hasColumn('hs_risk_assessments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
