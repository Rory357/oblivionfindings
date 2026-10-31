<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lone_worker_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1);
            $table->json('planned_location')->nullable();
            $table->uuid('creation_key')->nullable()->unique('lw_creation_key_unique');
            $table->string('creation_fingerprint', 64)->nullable();
            $table->unsignedInteger('emergency_episode_number')->default(0);
            $table->timestamp('last_monitor_checked_at')->nullable();
            $table->timestamp('monitor_failed_at')->nullable();
            $table->string('monitor_failure_code', 48)->nullable();
        });
        Schema::table('lone_worker_check_ins', function (Blueprint $table): void {
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users', indexName: 'lw_checkin_actor_fk')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
        });
        Schema::create('lone_worker_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lone_worker_session_id')->constrained('lone_worker_sessions', indexName: 'lw_receipt_source_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'lw_receipt_actor_fk')->restrictOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_logs', indexName: 'lw_receipt_audit_fk')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique('lw_receipt_key_unique');
            $table->string('command', 32);
            $table->string('fingerprint', 64);
            $table->json('confirmed_result');
            $table->timestamps();
        });
        Schema::create('lone_worker_emergency_episodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lone_worker_session_id')->constrained('lone_worker_sessions', indexName: 'lw_episode_source_fk')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites', indexName: 'lw_episode_site_fk')->restrictOnDelete();
            $table->unsignedInteger('episode_number');
            $table->foreignId('initiated_by_user_id')->constrained('users', indexName: 'lw_episode_actor_fk')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->string('status', 16)->default('pending');
            $table->string('signal_key', 64)->unique('lw_episode_signal_unique');
            $table->foreignId('signal_id')->nullable()->constrained('control_room_signals', indexName: 'lw_episode_signal_fk')->restrictOnDelete();
            $table->foreignId('control_room_alert_id')->nullable()->constrained('control_room_alerts', indexName: 'lw_episode_alert_fk')->restrictOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 48)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['lone_worker_session_id', 'episode_number'], 'lw_episode_number_unique');
            $table->index(['status', 'next_attempt_at'], 'lw_episode_retry_index');
        });
    }

    public function down(): void
    {
        $provenance = DB::table('lone_worker_sessions')->where('lock_version', '!=', 1)
            ->orWhereNotNull('creation_key')->orWhereNotNull('creation_fingerprint')->orWhereNotNull('planned_location')
            ->orWhere('emergency_episode_number', '>', 0)->orWhereNotNull('last_monitor_checked_at')
            ->orWhereNotNull('monitor_failed_at')->orWhereNotNull('monitor_failure_code')->exists();
        if ($provenance || DB::table('lone_worker_check_ins')->whereNotNull('recorded_by_user_id')->orWhereNotNull('received_at')->exists()
            || DB::table('lone_worker_command_receipts')->exists() || DB::table('lone_worker_emergency_episodes')->exists()) {
            throw new RuntimeException('Lone-worker command, observation or delivery provenance must be retained.');
        }
        Schema::dropIfExists('lone_worker_emergency_episodes');
        Schema::dropIfExists('lone_worker_command_receipts');
        Schema::table('lone_worker_check_ins', function (Blueprint $table): void {
            $table->dropForeign('lw_checkin_actor_fk');
            $table->dropColumn(['recorded_by_user_id', 'received_at']);
        });
        Schema::table('lone_worker_sessions', function (Blueprint $table): void {
            $table->dropUnique('lw_creation_key_unique');
            $table->dropColumn(['lock_version', 'planned_location', 'creation_key', 'creation_fingerprint', 'emergency_episode_number', 'last_monitor_checked_at', 'monitor_failed_at', 'monitor_failure_code']);
        });
    }
};
