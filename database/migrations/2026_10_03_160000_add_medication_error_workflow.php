<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_errors', function (Blueprint $table) {
            // Keep historical status/severity values; new workflow uses explicit fields.
            $table->string('workflow_stage', 24)->nullable()->index();
            $table->string('report_source', 24)->nullable();
            $table->uuid('report_token')->nullable()->unique();
            $table->char('report_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('triage_due_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('investigation_due_at')->nullable();
        });
        Schema::create('medication_error_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_error_id')->constrained('medication_errors')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->text('text')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at');
            $table->index(['medication_error_id', 'id']);
        });
        Schema::create('medication_error_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_error_id')->constrained('medication_errors')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->timestamp('due_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();
        });
        $permissionId = DB::table('permissions')->where('key', 'medications.errors.manage')->value('id');
        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => 'medications.errors.manage', 'description' => 'Triage, investigate and close medication errors',
                'group' => 'medications', 'module' => 'Clinical', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('roles')->whereIn('name', ['team_lead', 'clinical_lead', 'coordinator', 'provider_manager'])->pluck('id') as $roleId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_error_actions');
        Schema::dropIfExists('medication_error_entries');
        Schema::table('medication_errors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['workflow_stage', 'report_source', 'report_token', 'report_fingerprint', 'occurred_at', 'triage_due_at', 'investigation_due_at']);
        });
        // Permission grants may have been explicitly assigned since deployment.
    }
};
