<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet & Asset Incidents redesign — Step 1 (plan §3.10 / §6.2, Gap F3).
 *
 * Trackable operational follow-ups on a fleet/asset incident (assign / due /
 * complete) — mirrors `incident_followups` (client incidents) but FK'd to
 * `fleet_incidents` so the fleet module stays self-contained.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_incident_followups') || ! Schema::hasTable('fleet_incidents')) {
            return;
        }

        Schema::create('fleet_incident_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_incident_id')->constrained('fleet_incidents')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fleet_incident_id', 'completed_at'], 'fif_incident_done_idx');
            $table->index(['assigned_to_user_id', 'due_at'], 'fif_assignee_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_incident_followups');
    }
};
