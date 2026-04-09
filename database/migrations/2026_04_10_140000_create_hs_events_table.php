<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_events', function (Blueprint $table) {
            $table->id();

            // ── Tenant isolation ──
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // ── Reference ──
            $table->string('reference_number', 20)->unique();

            // ── Polymorphic source linkage ──
            $table->string('source_type', 100);  // e.g. App\Models\ClientIncident
            $table->unsignedBigInteger('source_id');

            // ── Event classification ──
            $table->string('event_category', 40);
            // Values: incident, near_miss, hazard, injury, exposure, restraint,
            //         safeguarding, drill_failure, inspection_failure,
            //         equipment_fault, vehicle_incident

            $table->string('severity', 20);
            // Normalised: low, medium, high, critical

            $table->string('status', 30)->default('open');
            // Lifecycle: open → investigating → corrective_action → monitoring → closed

            // ── When it happened ──
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('reported_at')->nullable();

            // ── Context references (all nullable — not every event has all) ──
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();

            // ── WorkSafe notification tracking ──
            $table->boolean('worksafe_notifiable')->default(false);
            $table->string('worksafe_status', 30)->nullable();
            // Values: null, pending, notified, acknowledged
            $table->string('worksafe_reference', 50)->nullable();

            // ── Investigation requirement ──
            $table->boolean('investigation_required')->default(false);

            // ── Control Room back-reference ──
            $table->unsignedBigInteger('control_room_alert_id')->nullable();

            // ── Closure ──
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('closure_summary')->nullable();

            // ── Idempotency ──
            $table->string('idempotency_key', 64)->unique();

            // ── Provenance ──
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes for common queries ──
            $table->index(['source_type', 'source_id']);
            $table->index(['site_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['event_category', 'status']);
            $table->index(['severity', 'status']);
            $table->index(['status', 'created_at']);

            // ── Foreign keys for nullable context ──
            $table->foreign('staff_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->nullOnDelete();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_events');
    }
};
