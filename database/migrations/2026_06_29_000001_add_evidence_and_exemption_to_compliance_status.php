<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the "record compliance" loop: the per-staff status row could not store a
 * manager's notes or an uploaded evidence file, and a waiver had no end date.
 *
 *  - notes              : free-text rationale rendered on the staff-detail page.
 *  - evidence_*         : a single uploaded file (private disk) proving the status,
 *                         kept distinct from evidence_type/evidence_id which the
 *                         nightly evaluator uses as a SOURCE discriminator
 *                         (training_record / credential / manual …).
 *  - evidence_category  : the UI's "evidence type" label (Certificate / Letter …).
 *  - exempted_until     : optional waiver end date; exempted_at stamps when granted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_staff_compliance_status', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('exemption_reason');
            $table->date('exempted_until')->nullable()->after('exempted_by');
            $table->timestamp('exempted_at')->nullable()->after('exempted_until');
            $table->string('evidence_disk')->nullable()->after('evidence_id');
            $table->string('evidence_path', 1024)->nullable()->after('evidence_disk');
            $table->string('evidence_filename')->nullable()->after('evidence_path');
            $table->string('evidence_mime')->nullable()->after('evidence_filename');
            $table->string('evidence_category')->nullable()->after('evidence_mime');
            $table->foreignId('recorded_by')->nullable()->after('evidence_category')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_staff_compliance_status', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn([
                'notes',
                'exempted_until',
                'exempted_at',
                'evidence_disk',
                'evidence_path',
                'evidence_filename',
                'evidence_mime',
                'evidence_category',
            ]);
        });
    }
};
