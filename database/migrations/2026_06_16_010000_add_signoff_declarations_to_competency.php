<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the two-party competency sign-off (assessor confirmation + staff
 * acknowledgement). The wizard already collects these declarations on the
 * Review & sign step; previously they were enforced client-side only and never
 * stored. Timestamps record when each party signed (the acting assessor is
 * already captured via assessor_id; the staff member via user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_competency_assessments', function (Blueprint $table) {
            $table->timestamp('assessor_declared_at')->nullable()->after('assessor_comments');
            $table->timestamp('staff_acknowledged_at')->nullable()->after('assessor_declared_at');
        });
    }

    public function down(): void
    {
        Schema::table('medication_competency_assessments', function (Blueprint $table) {
            $table->dropColumn(['assessor_declared_at', 'staff_acknowledged_at']);
        });
    }
};
