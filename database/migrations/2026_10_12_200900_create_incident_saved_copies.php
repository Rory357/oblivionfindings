<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_saved_copies', function (Blueprint $table): void {
            $table->id(); $table->uuid('copy_uuid')->unique(); $table->uuid('source_version')->unique();
            $table->unsignedBigInteger('incident_id');
            $table->foreign('incident_id', 'isc_incident_fk')->references('id')->on('client_incidents')->restrictOnDelete();
            $table->string('source_family', 40); $table->json('source_tuple');
            $table->string('target_family', 40); $table->string('target_key', 80); $table->string('field', 40);
            $table->unsignedBigInteger('actor_id');
            $table->foreign('actor_id', 'isc_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->longText('snapshot_text'); $table->dateTime('recorded_at', 6); $table->char('content_digest', 64);
            $table->unsignedBigInteger('audit_id')->nullable();
            $table->foreign('audit_id', 'isc_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
            $table->index(['target_family', 'target_key'], 'isc_target_idx');
            $table->index(['incident_id', 'id'], 'isc_source_idx');
        });
        // No legacy copy, present association or best-effort audit is backfilled as original proof.
    }

    public function down(): void
    {
        if ((Schema::hasTable('incident_saved_copies') && DB::table('incident_saved_copies')->exists())
            || DB::table('audit_logs')->where(fn ($query) => $query->where('action', 'incidents.savedCopy.captured')
                ->orWhereIn('auditable_type', [\App\Models\IncidentSavedCopy::class, (new \App\Models\IncidentSavedCopy)->getMorphClass()]))->exists()) {
            throw new RuntimeException('Incident saved copies and their provenance must be retained.');
        }
        Schema::dropIfExists('incident_saved_copies');
    }
};
