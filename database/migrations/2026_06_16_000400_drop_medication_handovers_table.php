<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the dead `medication_handovers` table. It was redundant with
 * `shift_handovers` (the canonical, rostering-FK'd handover record) and was only
 * ever written by the demo seeder — the `MedicationHandover` model and that
 * seeder block have been removed. down() recreates the original structure (create
 * table + the later checklist-fields migration) for reversibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('medication_handovers');
    }

    public function down(): void
    {
        Schema::create('medication_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('service_context_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('outgoing_user_id')->constrained('users');
            $table->foreignId('incoming_user_id')->constrained('users');
            $table->timestamp('handover_at');
            $table->json('controlled_drug_counts')->nullable();
            $table->boolean('controlled_drugs_verified')->default(false);
            $table->json('outstanding_medications')->nullable();
            $table->json('new_prescriptions')->nullable();
            $table->json('ceased_medications')->nullable();
            $table->json('incidents')->nullable();
            $table->json('prn_given')->nullable();
            $table->json('flagged_clients')->nullable();
            $table->text('general_notes')->nullable();
            // Checklist fields (originally added by 2026_03_27_000003).
            $table->json('checklist_items')->nullable();
            $table->text('safety_concerns')->nullable();
            $table->integer('medication_errors_count')->default(0);
            $table->integer('pending_gp_followups')->default(0);
            $table->json('clients_requiring_attention')->nullable();
            $table->boolean('previous_shift_notes_read')->default(false);
            $table->text('stock_issues_identified')->nullable();
            $table->text('prescriber_changes_summary')->nullable();
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'handover_at']);
        });
    }
};
