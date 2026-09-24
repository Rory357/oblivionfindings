<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B vehicle map: a vehicle's assignment to a shared geofence boundary.
 *
 * The boundary itself stays the canonical AssetGeofence. An assignment keeps
 * this vehicle's purpose, schedule and response proposal, and the boundary
 * version it was reviewed against. Assignments are always saved inactive:
 * the fleet geofence evaluator reads only asset_geofences.asset_id and
 * asset_geofence_assignments, never this table, so linking a boundary here
 * cannot start monitoring or alerts. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_geofence_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            // A boundary removed from the register leaves the assignment for review.
            $table->foreignId('geofence_id')->nullable()
                ->constrained('asset_geofences', 'id', 'fleet_vehicle_geofence_boundary_fk')->nullOnDelete();
            $table->string('label', 120);
            $table->string('origin', 24)->default('linked');
            $table->text('purpose')->nullable();
            $table->text('response_proposal')->nullable();
            $table->json('schedule')->nullable();
            $table->char('geometry_hash', 64);
            $table->json('geometry_snapshot');
            $table->string('monitoring', 24)->default('inactive');
            $table->string('state', 24)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->char('payload_hash', 64)->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_vehicle_geofence_creator_fk')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_vehicle_geofence_editor_fk')->nullOnDelete();
            $table->foreignId('removed_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_vehicle_geofence_remover_fk')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'request_key'], 'fleet_vehicle_geofence_request_uq');
            $table->index(['asset_id', 'state'], 'fleet_vehicle_geofence_state_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('fleet_vehicle_geofence_assignments')->exists()) {
            throw new RuntimeException('PKG-02B vehicle geofence assignments exist; preserve them instead of rolling back.');
        }

        Schema::dropIfExists('fleet_vehicle_geofence_assignments');
    }
};
