<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_followups', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 190)->unique();
            $table->string('type', 40);
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->restrictOnDelete();
            $table->foreignId('administration_id')->nullable()->constrained('client_medication_administrations')->restrictOnDelete();
            $table->foreignId('original_owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('state', 30)->default('open');
            $table->unsignedInteger('revision')->default(1);
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'completed_at', 'due_at'], 'med_followups_open_due');
        });
        Schema::create('medication_followup_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medication_followup_id')->constrained('medication_followups')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->uuid('request_uuid')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->json('data');
            $table->timestamp('created_at');
            $table->unique(['medication_followup_id', 'request_uuid'], 'med_followup_request');
        });

        // First grant only: a replay never restores a deliberately revoked grant.
        $created = DB::table('permissions')->insertOrIgnore([
            'key' => 'medications.followups.manage', 'description' => 'Manage medication follow-ups and lead sign-offs',
            'group' => 'medications', 'module' => 'Clinical', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($created) {
            $permission = DB::table('permissions')->where('key', 'medications.followups.manage')->value('id');
            foreach (DB::table('roles')->whereIn('name', ['admin', 'team_lead', 'coordinator', 'clinical_lead', 'provider_manager'])->pluck('id') as $role) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Medication follow-up evidence cannot be rolled back destructively. Deploy an evidence-preserving forward migration.');
    }
};
