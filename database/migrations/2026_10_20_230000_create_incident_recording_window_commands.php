<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_evidence_window_command_receipts', function (Blueprint $table): void {
            $table->id(); $table->uuid('idempotency_key')->unique('iewcr_key_unique');
            $table->unsignedBigInteger('incident_id'); $table->foreignId('actor_user_id')->constrained('users', indexName: 'iewcr_actor_fk')->restrictOnDelete();
            $table->string('command', 40); $table->char('fingerprint', 64);
            $table->unsignedInteger('submitted_evidence_version'); $table->unsignedInteger('submitted_policy_revision');
            $table->unsignedInteger('confirmed_evidence_version'); $table->unsignedInteger('window_revision');
            $table->longText('encrypted_input'); $table->json('confirmed_result'); $table->dateTime('confirmed_at', 6);
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'iewcr_audit_fk')->restrictOnDelete();
            $table->unique(['incident_id', 'window_revision'], 'iewcr_original_unique');
            $table->foreign(['incident_id', 'window_revision'], 'iewcr_window_fk')
                ->references(['incident_id', 'window_revision'])->on('incident_evidence_windows')->restrictOnDelete();
            $table->index(['actor_user_id', 'incident_id', 'id'], 'iewcr_actor_incident_idx');
        });
        DB::statement("ALTER TABLE incident_evidence_window_command_receipts ADD CONSTRAINT iewcr_values_check CHECK (
            command = 'record_evidence_window' AND submitted_evidence_version BETWEEN 0 AND 2147483646
            AND submitted_policy_revision BETWEEN 0 AND 2147483646 AND confirmed_evidence_version = submitted_evidence_version + 1
            AND window_revision BETWEEN 1 AND 2147483647)");
        // Catalogue registration ONLY. No role assignment, direct override, backfill or inherited grant.
        foreach (['incidents.evidence.attach' => 'Attach an independently authorised incident recording reference',
            'incidents.evidence.window.record' => 'Record a policy-approved incident evidence window',
            'incidents.evidence.window.expand' => 'Expand an incident evidence window within its configured policy bounds'] as $key => $description) {
            Permission::firstOrCreate(['key' => $key], ['description' => $description, 'group' => 'incidents', 'module' => 'Compliance']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('incident_evidence_window_command_receipts') && DB::table('incident_evidence_window_command_receipts')->exists()) {
            throw new RuntimeException('Original incident window command provenance must be retained.');
        }
        Schema::dropIfExists('incident_evidence_window_command_receipts');
        // Permission catalogue removal is a separately governed dependency decision.
    }
};
