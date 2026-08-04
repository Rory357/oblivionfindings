<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->foreignId('collector_device_id')->nullable()->after('site_id');
            $table->text('public_key')->nullable()->after('collector_device_id');
            $table->char('public_key_fingerprint', 64)->nullable()->after('public_key');
            $table->char('client_certificate_fingerprint', 64)->nullable()->after('public_key_fingerprint');
            $table->unsignedBigInteger('configuration_sequence')->default(0)->after('client_certificate_fingerprint');
            $table->unsignedBigInteger('acknowledged_source_sequence')->default(0)->after('configuration_sequence');
            $table->unsignedBigInteger('highest_seen_source_sequence')->default(0)->after('acknowledged_source_sequence');
            $table->unsignedInteger('backlog_items')->default(0)->after('highest_seen_source_sequence');
            $table->unsignedBigInteger('spool_bytes')->default(0)->after('backlog_items');
            $table->unsignedInteger('corrupted_frames')->default(0)->after('spool_bytes');
            $table->string('runtime_state', 32)->nullable()->after('corrupted_frames');
            $table->json('runtime_status')->nullable()->after('runtime_state');
            $table->unsignedInteger('gap_count')->default(0)->after('runtime_status');
            $table->integer('last_clock_drift_seconds')->nullable()->after('gap_count');
            $table->timestamp('backlog_oldest_at')->nullable()->after('last_clock_drift_seconds');
            $table->timestamp('last_heartbeat_at')->nullable()->after('backlog_oldest_at');
            $table->timestamp('enrolled_at')->nullable()->after('last_heartbeat_at');
            $table->timestamp('revoked_at')->nullable()->after('enrolled_at');
            $table->timestamp('last_recovered_at')->nullable()->after('revoked_at');

            $table->foreign('collector_device_id', 'monitoring_collectors_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->unique('collector_device_id', 'monitoring_collectors_device_uq');
            $table->unique('public_key_fingerprint', 'monitoring_collectors_public_key_uq');
            $table->unique('client_certificate_fingerprint', 'monitoring_collectors_certificate_uq');
            $table->index(['revoked_at', 'last_heartbeat_at'], 'monitoring_collectors_lifecycle_idx');
        });

        Schema::create('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->foreignId('issued_by_user_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_collector_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_collector_enrol_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('issued_by_user_id', 'monitoring_collector_enrol_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('consumed_collector_id', 'monitoring_collector_enrol_used_fk')
                ->references('id')->on('monitoring_collectors')->restrictOnDelete();
            $table->index(['site_id', 'expires_at'], 'monitoring_collector_enrol_site_expiry_idx');
        });

        Schema::create('monitoring_collector_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collector_id')->unique();
            $table->unsignedBigInteger('acknowledged_source_sequence')->default(0);
            $table->unsignedBigInteger('highest_seen_source_sequence')->default(0);
            $table->unsignedBigInteger('gap_from')->nullable();
            $table->unsignedBigInteger('gap_to')->nullable();
            $table->timestamp('last_item_at')->nullable();
            $table->timestamp('last_acknowledged_at')->nullable();
            $table->timestamp('last_gap_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->foreign('collector_id', 'monitoring_collector_checkpoint_fk')
                ->references('id')->on('monitoring_collectors')->cascadeOnDelete();
            $table->index(['gap_from', 'last_gap_at'], 'monitoring_collector_checkpoint_gap_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_collector_checkpoints');
        Schema::dropIfExists('monitoring_collector_enrollments');

        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->dropIndex('monitoring_collectors_lifecycle_idx');
            $table->dropUnique('monitoring_collectors_certificate_uq');
            $table->dropUnique('monitoring_collectors_public_key_uq');
            $table->dropUnique('monitoring_collectors_device_uq');
            $table->dropForeign('monitoring_collectors_device_fk');
            $table->dropColumn([
                'collector_device_id', 'public_key', 'public_key_fingerprint',
                'client_certificate_fingerprint', 'configuration_sequence',
                'acknowledged_source_sequence', 'highest_seen_source_sequence',
                'backlog_items', 'gap_count', 'last_clock_drift_seconds',
                'spool_bytes', 'corrupted_frames', 'runtime_state', 'runtime_status',
                'backlog_oldest_at', 'last_heartbeat_at', 'enrolled_at',
                'revoked_at', 'last_recovered_at',
            ]);
        });
    }
};
