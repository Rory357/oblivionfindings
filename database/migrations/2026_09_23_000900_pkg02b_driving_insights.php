<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B vehicle Driving insights.
 *
 * - `fleet_driving_score_policies`: published versions of the trip scoring
 *   policy. Version 1 is always the fleet configuration (config/fleet.php);
 *   a published version supersedes it on every surface that scores trips and
 *   earlier versions stay as history.
 * - `fleet_driving_event_reviews`: human reviews of recorded driving events
 *   (confirmed, dismissed or disputed). Rows are only added; the original
 *   telemetry is never changed.
 * - `fleet_speed_limits` and their events: manually recorded road speed
 *   limits for a vehicle's evaluations, pending until someone other than the
 *   proposer approves them with evidence.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_driving_score_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique('fleet_drv_policy_version_uq');
            $table->decimal('braking_weight', 6, 2);
            $table->decimal('acceleration_weight', 6, 2);
            $table->decimal('overspeed_weight', 6, 2);
            $table->decimal('idle_weight', 6, 2);
            $table->unsignedTinyInteger('min_coverage_pct');
            $table->unsignedSmallInteger('min_trips');
            $table->decimal('min_distance_km', 8, 1);
            $table->text('reason');
            // The vehicle profile the version was published from (provenance only).
            $table->foreignId('asset_id')->nullable()
                ->constrained('assets', 'id', 'fleet_drv_policy_asset_fk')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_drv_policy_actor_fk')->nullOnDelete();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['published_by_user_id', 'request_key'], 'fleet_drv_policy_request_uq');
        });

        Schema::create('fleet_driving_event_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('assets', 'id', 'fleet_drv_review_asset_fk')->cascadeOnDelete();
            $table->foreignId('fleet_trip_id')
                ->constrained('fleet_trips', 'id', 'fleet_drv_review_trip_fk')->cascadeOnDelete();
            // The recorded event: its type and source report, never a copy of the telemetry.
            $table->string('event_key', 120);
            $table->string('event_type', 40);
            $table->string('event_title', 120);
            $table->timestamp('event_at')->nullable();
            // confirmed | dismissed | disputed
            $table->string('outcome', 16);
            $table->text('reason');
            $table->foreignId('review_owner_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_drv_review_owner_fk')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_drv_review_actor_fk')->nullOnDelete();
            $table->unsignedInteger('policy_version');
            // 1, 2, … per trip event; a concurrent second review of the same
            // version cannot both land.
            $table->unsignedInteger('sequence');
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['fleet_trip_id', 'event_key', 'sequence'], 'fleet_drv_review_sequence_uq');
            $table->unique(['asset_id', 'request_key'], 'fleet_drv_review_request_uq');
            $table->index(['asset_id', 'fleet_trip_id'], 'fleet_drv_review_asset_trip_idx');
        });

        Schema::create('fleet_speed_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('assets', 'id', 'fleet_speed_limit_asset_fk')->cascadeOnDelete();
            $table->string('road_segment', 160);
            $table->string('direction', 40);
            $table->unsignedSmallInteger('limit_kph');
            $table->timestamp('effective_from');
            $table->timestamp('expires_at');
            $table->text('reason');
            // pending | approved | retired
            $table->string('status', 16)->default('pending');
            $table->foreignId('proposed_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_speed_limit_proposer_fk')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_speed_limit_reviewer_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('retired_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_speed_limit_retirer_fk')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['asset_id', 'request_key'], 'fleet_speed_limit_request_uq');
            $table->index(['asset_id', 'status'], 'fleet_speed_limit_asset_status_idx');
        });

        Schema::create('fleet_speed_limit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('speed_limit_id')
                ->constrained('fleet_speed_limits', 'id', 'fleet_speed_limit_event_fk')->cascadeOnDelete();
            // proposed | approved | retired
            $table->string('action', 16);
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_speed_limit_event_actor_fk')->nullOnDelete();
            $table->text('note');
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['speed_limit_id', 'request_key'], 'fleet_speed_limit_event_request_uq');
        });
    }

    public function down(): void
    {
        foreach (['fleet_driving_score_policies', 'fleet_driving_event_reviews', 'fleet_speed_limits'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('PKG-02B driving reviews, scoring policies or speed limits exist; preserve them instead of rolling back.');
            }
        }

        Schema::dropIfExists('fleet_speed_limit_events');
        Schema::dropIfExists('fleet_speed_limits');
        Schema::dropIfExists('fleet_driving_event_reviews');
        Schema::dropIfExists('fleet_driving_score_policies');
    }
};
