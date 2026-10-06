<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table): void {
            $table->string('nz_controlled_class', 1)->nullable();
            $table->foreignId('controlled_class_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('controlled_class_reviewed_at')->nullable();
            $table->text('controlled_class_source')->nullable();
        });
        // Legacy schedule values stay untouched: only a reviewed source can set the NZ class.
        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            // Retain a supporting FK index before dropping the old delivery uniqueness.
            $table->index('pharmacy_order_id', 'cd_entries_pharmacy_order_receipts');
        });
        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('client_controlled_drug_entries')->restrictOnDelete();
            $table->foreignId('second_witness_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('first_count', 10, 2)->nullable();
            $table->decimal('recount', 10, 2)->nullable();
            $table->timestamp('count_due_at')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->index(['source_type', 'source_id']);
            // A pharmacy order may arrive in multiple separately witnessed deliveries.
            $table->dropUnique(['pharmacy_order_id']);
        });
        Schema::table('client_controlled_drug_discrepancies', function (Blueprint $table): void {
            $table->foreignId('count_entry_id')->nullable()->constrained('client_controlled_drug_entries')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolution_outcome', 20)->nullable();
        });
        Schema::table('controlled_drug_loss_reports', function (Blueprint $table): void {
            $table->foreignId('register_entry_id')->nullable()->constrained('client_controlled_drug_entries')->restrictOnDelete();
            $table->boolean('suspected_theft')->default(false);
        });
        Schema::table('medication_destructions', function (Blueprint $table): void {
            $table->foreignId('register_entry_id')->nullable()->constrained('client_controlled_drug_entries')->restrictOnDelete();
            $table->timestamp('pharmacy_received_at')->nullable();
            $table->string('pharmacist_name')->nullable();
            $table->string('pharmacist_registration')->nullable();
        });
        Schema::create('controlled_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_medication_id')->constrained()->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 40);
            $table->json('payload');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id', 'id']);
        });
        Schema::create('controlled_witness_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_medication_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('addressed_to')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->string('response', 20)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('controlled_witness_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_medication_id')->constrained()->restrictOnDelete();
            $table->json('medicine_ids');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('waiting');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('followup_due_at')->nullable();
            $table->timestamp('signed_off_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'expires_at']);
        });
        Schema::table('client_medication_administrations', function (Blueprint $table): void {
            $table->foreignId('witness_override_id')->nullable()->constrained('controlled_witness_overrides')->restrictOnDelete();
        });
        Schema::create('controlled_product_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_uuid');
            $table->string('fingerprint', 64);
            $table->json('result');
            $table->timestamps();
            $table->unique(['actor_id', 'request_uuid']);
        });
        $permissionId = DB::table('permissions')->where('key', 'medications.controlled.manage')->value('id');
        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => 'medications.controlled.manage', 'group' => 'medications',
                'module' => 'medications', 'description' => 'Independent discrepancy resolution, witnessed corrections and destruction sign-off',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('roles')->whereIn('name', ['team_lead', 'provider_manager'])->pluck('id') as $roleId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Controlled medicine evidence is append-only. Review dependencies and preserve records before a separately approved rollback.');
    }
};
