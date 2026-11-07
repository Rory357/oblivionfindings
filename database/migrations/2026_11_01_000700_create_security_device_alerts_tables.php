<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase D (Alerts): one alert per device problem that someone can acknowledge, assign or
 * snooze, with a timeline of what happened and who was told. Opened and closed by Oblivion
 * from the device's checks; people only ever add to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            // offline | failing | hub_offline (later phases add safety kinds)
            $table->string('kind', 32);
            $table->string('severity', 16); // critical | warning
            $table->string('title', 191);
            $table->string('state', 16)->default('open'); // open | snoozed | done
            $table->foreignId('monitor_id')->nullable()->constrained('monitors')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('last_failing_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('acknowledgement_note')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->foreignId('snoozed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 32)->nullable(); // recovered | removed
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'opened_at'], 'sd_alerts_state_opened_idx');
            $table->index(['device_id', 'state'], 'sd_alerts_device_state_idx');
            $table->index(['site_id', 'state'], 'sd_alerts_site_state_idx');
        });

        Schema::create('security_device_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('security_device_alerts')->cascadeOnDelete();
            $table->timestamp('at');
            // opened | told | worse | reminded | acknowledged | assigned | snoozed | back | recovered | incident
            $table->string('kind', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('text', 255);
            $table->string('detail', 500)->nullable();
            $table->timestamps();

            $table->index(['alert_id', 'at'], 'sd_alert_events_alert_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_alert_events');
        Schema::dropIfExists('security_device_alerts');
    }
};
