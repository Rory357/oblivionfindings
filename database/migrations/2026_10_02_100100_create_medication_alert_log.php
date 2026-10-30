<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The medication alert log (eMAR P11 B2): one row per alert raised — the
 * record every recipient shares — with who was told, how, and what happened
 * since. Kept as long as the audit log (P11 Q11). It never changes the
 * medication record.
 *
 * `open_key` holds the alert's subject key while it is open and is cleared
 * when it is dealt with, so a unique index allows only one open alert per
 * subject: raising the same alert twice — or two schedulers racing — tells
 * people once. The database is the de-duplication record because cache keys
 * are cleared on every deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('dedupe_key', 191);
            $table->string('open_key', 191)->nullable()->unique();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('staff_user_id')->nullable()->index();
            $table->boolean('controlled')->default(false);
            $table->string('title', 191);
            $table->text('message');
            $table->string('short_message', 191);
            $table->string('action_url', 500)->nullable();
            $table->string('severity', 20)->default('warning');
            $table->json('subject')->nullable();
            $table->boolean('follow_up')->default(false);
            $table->string('status', 20)->default('open');
            $table->timestamp('raised_at');
            $table->timestamp('attended_at')->nullable();
            $table->unsignedBigInteger('attended_by')->nullable();
            $table->string('attended_how', 20)->nullable();
            $table->timestamp('dealt_with_at')->nullable();
            $table->string('outcome', 191)->nullable();
            $table->boolean('after_hours')->default(false);
            $table->string('after_hours_source', 20)->nullable();
            // Not even the safety net could be told: Settings counts these.
            $table->boolean('reached_nobody')->default(false);
            $table->timestamp('next_follow_up_at')->nullable();
            $table->unsignedSmallInteger('realert_count')->default(0);
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['site_id', 'raised_at']);
            $table->index('dedupe_key');
            $table->index('next_follow_up_at');
        });

        Schema::create('medication_alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_alert_id')->constrained('medication_alerts')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            // The group that told them, "named", "extra", "fallback" or "escalation".
            $table->string('reason', 40);
            // 0 = when it happened; then each re-alert or escalation step.
            $table->unsignedSmallInteger('step')->default(0);
            $table->json('channels');
            $table->timestamp('told_at')->nullable();
            $table->char('notification_id', 36)->nullable();
            $table->timestamp('held_until')->nullable();
            $table->timestamps();

            $table->unique(['medication_alert_id', 'user_id', 'step'], 'medication_alert_recipient_step_unique');
        });

        Schema::create('medication_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_alert_id')->constrained('medication_alerts')->cascadeOnDelete();
            // sent, opened, acknowledged, dealt_with, re_alerted, escalated,
            // held, released, not_told_controlled,
            // fallback_to_settings_managers, nobody_told
            $table->string('event', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['medication_alert_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_alert_events');
        Schema::dropIfExists('medication_alert_recipients');
        Schema::dropIfExists('medication_alerts');
    }
};
