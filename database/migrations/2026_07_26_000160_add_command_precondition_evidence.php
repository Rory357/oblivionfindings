<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->string('assignment_fingerprint', 64)->nullable()->after('site_id');
            $table->string('blocked_reason_code', 80)->nullable()->after('safe_failure_reason');
            $table->timestamp('blocked_at')->nullable()->after('cancelled_at');
            $table->index(['status', 'blocked_at'], 'device_command_requests_status_blocked_index');
        });

        Schema::create('device_command_intake_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('device_command_request_id')->nullable()
                ->constrained('device_command_requests')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome', 20);
            $table->string('safe_reason_code', 80);
            $table->string('target_fingerprint', 64);
            $table->string('capability', 120)->nullable();
            $table->string('capability_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['actor_user_id', 'occurred_at'], 'device_command_intake_actor_occurred_index');
            $table->index(['outcome', 'occurred_at'], 'device_command_intake_outcome_occurred_index');
            $table->index('target_fingerprint', 'device_command_intake_target_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_command_intake_audits');

        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->dropIndex('device_command_requests_status_blocked_index');
            $table->dropColumn(['assignment_fingerprint', 'blocked_reason_code', 'blocked_at']);
        });
    }
};
