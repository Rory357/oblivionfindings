<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the legacy HrJobPosting subsystem. Applications are sourced from
 * HrJobRequisition (requisition_id); the public careers detail + admin
 * surfaces have been removed. Drop the dead foreign key then the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hr_applications', 'job_posting_id')) {
            Schema::table('hr_applications', function (Blueprint $table) {
                // Drop the FK constraint if one exists, then the column.
                try {
                    $table->dropForeign(['job_posting_id']);
                } catch (\Throwable $e) {
                    // no FK / already gone
                }
                $table->dropColumn('job_posting_id');
            });
        }

        Schema::dropIfExists('hr_job_postings');
    }

    public function down(): void
    {
        // Irreversible consolidation onto requisitions; recreate as a bare stub.
        if (! Schema::hasColumn('hr_applications', 'job_posting_id')) {
            Schema::table('hr_applications', function (Blueprint $table) {
                $table->unsignedBigInteger('job_posting_id')->nullable()->after('requisition_id');
            });
        }
    }
};
