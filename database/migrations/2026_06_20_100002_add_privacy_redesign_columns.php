<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy command-centre redesign — Step 1. Additive schema gaps the rebuild needs:
 *  - privacy_impact_assessments.review_notes — the dpia.review verb validated a
 *    `review_notes` field but had no column to persist it (silently dropped).
 *  - data_retention_policies.next_review_at — drives the hero "retention review
 *    due" metric and the policy worklist "Next review" column.
 *  - data_subject_requests.request_details made nullable — validation already
 *    allowed null but the column was NOT NULL (strict-MySQL insert failures).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacy_impact_assessments', function (Blueprint $table) {
            $table->text('review_notes')->nullable()->after('outcome');
        });

        Schema::table('data_retention_policies', function (Blueprint $table) {
            $table->date('next_review_at')->nullable()->after('last_applied_at');
        });

        Schema::table('data_subject_requests', function (Blueprint $table) {
            $table->text('request_details')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('privacy_impact_assessments', function (Blueprint $table) {
            $table->dropColumn('review_notes');
        });

        Schema::table('data_retention_policies', function (Blueprint $table) {
            $table->dropColumn('next_review_at');
        });

        Schema::table('data_subject_requests', function (Blueprint $table) {
            $table->text('request_details')->nullable(false)->change();
        });
    }
};
