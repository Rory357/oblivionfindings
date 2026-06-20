<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Controlled-document fields the redesign's wizard captures and the detail modal shows:
     *  - owner_id: the responsible document owner (distinct from whoever created the record).
     *  - review_frequency_months: the review cadence, used to auto-compute the next review_date
     *    on approval / record-review and to drive review-due signalling.
     */
    public function up(): void
    {
        Schema::table('safe_work_procedures', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable()->after('approved_at');
            $table->unsignedSmallInteger('review_frequency_months')->nullable()->after('review_date');

            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('safe_work_procedures', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropColumn(['owner_id', 'review_frequency_months']);
        });
    }
};
