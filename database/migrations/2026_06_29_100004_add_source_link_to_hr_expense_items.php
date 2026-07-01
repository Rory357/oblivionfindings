<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 9 of the Training Hub handover: training-fee expense items carry a
 * polymorphic source link back to the originating HrCourse / HrCourseEnrollment
 * so the GL double-count rule (item 10) can reconcile a staff reimbursement
 * against the provider-invoice posting on the enrollment.
 */
return new class extends Migration {
    public function up(): void
    {
        // Guarded: the committed test schema dump already contains these
        // columns, so this migration must be a no-op when it re-runs on top
        // of the dump (test bootstrap runs pending migrations after loading
        // it; an unguarded duplicate-column failure silently aborts every
        // later migration).
        if (Schema::hasColumn('hr_expense_items', 'source_type')) {
            return;
        }

        Schema::table('hr_expense_items', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('notes');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hr_expense_items', 'source_type')) {
            return;
        }

        Schema::table('hr_expense_items', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
