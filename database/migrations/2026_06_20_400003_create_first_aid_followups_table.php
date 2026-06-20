<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First Aid Register gold-standard upgrade — Step 1 (docs/first-aid-redesign §0.6).
 *
 * Trackable follow-ups on a first-aid record (assign / due / complete) — e.g. re-check
 * the wound tomorrow, lodge the ACC45, notify whānau. Mirrors fleet_incident_followups,
 * FK'd to first_aid_records so the register stays self-contained. first_aider_notes
 * remains the at-capture notes field; follow-ups are the post-treatment actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('first_aid_followups') || ! Schema::hasTable('first_aid_records')) {
            return;
        }

        Schema::create('first_aid_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('first_aid_record_id')->constrained('first_aid_records')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['first_aid_record_id', 'completed_at'], 'faf_record_done_idx');
            $table->index(['assigned_to_user_id', 'due_at'], 'faf_assignee_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_aid_followups');
    }
};
