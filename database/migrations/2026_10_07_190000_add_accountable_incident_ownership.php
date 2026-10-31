<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->foreignId('incident_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('owner_revision')->default(0);
            $table->index(['incident_owner_user_id', 'status'], 'incident_owner_status_idx');
        });
        Schema::create('incident_owner_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('client_incidents')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('previous_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_owner_name')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->text('reason')->nullable();
            $table->string('source', 32);
            $table->string('routing_version')->nullable();
            $table->uuid('request_uuid')->unique();
            $table->char('request_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['incident_id', 'revision'], 'incident_owner_revision_unique');
        });
        Schema::create('incident_owner_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('change_id')->constrained('incident_owner_changes')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_role', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['change_id', 'recipient_role'], 'incident_owner_delivery_unique');
            $table->index(['status', 'updated_at']);
        });
        Schema::create('incident_owner_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('client_incidents')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 40);
            $table->timestamps();
            $table->unique(['incident_id', 'user_id']);
        });
        // Existing reports keep nullable ownership. No reporter/investigator is inferred.
    }

    public function down(): void
    {
        if (DB::table('incident_owner_changes')->exists() || DB::table('incident_owner_exclusions')->exists()) {
            throw new RuntimeException('Incident ownership history exists. Retain the additive schema when rolling application code back; archive/reconcile evidence before schema removal.');
        }
        Schema::dropIfExists('incident_owner_exclusions');
        Schema::dropIfExists('incident_owner_deliveries');
        Schema::dropIfExists('incident_owner_changes');
        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->dropIndex('incident_owner_status_idx');
            $table->dropConstrainedForeignId('incident_owner_user_id');
            $table->dropColumn('owner_revision');
        });
    }
};
