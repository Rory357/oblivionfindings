<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_geofences', function (Blueprint $table): void {
            $table->unsignedInteger('geometry_version')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->string('address', 500)->nullable();
            $table->json('permitted_uses')->nullable();
            $table->boolean('client_location_eligible')->default(false);
            $table->timestamp('retired_at')->nullable()->index();
            $table->json('copy_source')->nullable();
            $table->index(['site_id', 'retired_at', 'name'], 'boundary_catalogue_idx');
        });
        Schema::create('boundary_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('boundary_id')->constrained('asset_geofences')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('geometry_version');
            $table->string('category', 24);
            $table->json('snapshot');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 1000);
            $table->timestamp('recorded_at');
            $table->unique(['boundary_id', 'revision']);
            $table->index(['boundary_id', 'recorded_at']);
        });
        // Extend the existing inactive assignment register to cover assets too.
        // This is not an additional evaluator and never creates legacy monitoring links.
        Schema::table('fleet_vehicle_geofence_assignments', function (Blueprint $table): void {
            $table->json('policy_proposal')->nullable();
        });
        Schema::create('boundary_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_key', 100);
            $table->char('fingerprint', 64);
            $table->foreignId('boundary_id')->constrained('asset_geofences')->restrictOnDelete();
            $table->unique(['actor_id', 'request_key']);
        });
        Schema::create('boundary_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('fleet_vehicle_geofence_assignments', 'id', 'boundary_rule_assignment_fk')->restrictOnDelete();
            $table->foreignId('boundary_id')->constrained('asset_geofences')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 1000);
            $table->timestamp('recorded_at');
            $table->unique(['assignment_id', 'revision']);
        });
        Schema::create('boundary_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->char('access_fingerprint', 64);
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('boundary_id')->nullable()->constrained('asset_geofences')->restrictOnDelete();
            $table->char('geometry_hash', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('boundary_versions')->exists() || DB::table('boundary_handoffs')->exists()
            || DB::table('boundary_rule_versions')->exists() || DB::table('boundary_requests')->exists()
            || DB::table('fleet_vehicle_geofence_assignments')->whereNotNull('policy_proposal')->exists()) {
            throw new RuntimeException('Boundary evidence exists. Preserve it and use a forward migration.');
        }
        Schema::dropIfExists('boundary_handoffs');
        Schema::dropIfExists('boundary_rule_versions');
        Schema::dropIfExists('boundary_requests');
        Schema::dropIfExists('boundary_versions');
        Schema::table('fleet_vehicle_geofence_assignments', fn (Blueprint $t) => $t->dropColumn('policy_proposal'));
        Schema::table('asset_geofences', function (Blueprint $t): void {
            $t->dropIndex('boundary_catalogue_idx');
            $t->dropColumn(['geometry_version', 'revision', 'address', 'permitted_uses', 'client_location_eligible', 'retired_at', 'copy_source']);
        });
    }
};
