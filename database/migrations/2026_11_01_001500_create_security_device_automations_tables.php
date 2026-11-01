<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase F (drawing pg-sdr-13b, pop-ups 40 and 41): a house's automations: when this happens,
 * only if…, then do this.
 *
 * - An automation belongs to one Site. Its trigger is a door opening, a sensor reading crossing a
 *   value, a device going offline or coming back, or a time of day (NZ wall clock). Its actions are
 *   SafeActions::ACTIONS only (D12): nothing automatic ever disarms, unlocks or loosens privacy.
 * - `state` remembers what the engine needs between triggers: which side of its value each sensor
 *   was on, when it last ran for each device (a short pause stops a door that keeps opening from
 *   telling staff every few seconds) and the last time-of-day start it ran for.
 * - Removing one keeps it (and its runs) in history; it never runs again.
 * - The run log keeps every run, real or a test, with exactly what each action did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_automations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('trigger_kind', 24); // door_opened | reading | device_offline | device_back | time
            $table->json('trigger'); // {"kind": "reading", "device_id": 12, "metric": "temperature", "direction": "above", "value": 45}
            $table->json('conditions')->nullable(); // {"between": {"from": "22:00", "to": "06:00"}}
            $table->json('actions'); // [{"action": "notify", "who": "on_shift", "email": false, "push": false, "message": null}, …]
            $table->boolean('is_on')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->json('state')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('last_run_test')->default(false);
            $table->string('last_run_outcome', 16)->nullable(); // ok | partial | failed
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trigger_kind', 'is_on'], 'sd_automations_trigger_on_idx');
            $table->index(['site_id', 'removed_at'], 'sd_automations_site_idx');
        });

        Schema::create('security_device_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('security_device_automations')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->boolean('test')->default(false);
            $table->string('source', 24); // what started it: the trigger kind, or "test"
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete(); // the person testing
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete(); // the device that started it
            $table->string('outcome', 16); // ok | partial | failed
            $table->string('summary', 191);
            $table->json('lines'); // [{"action": "notify", "tone": "success", "text": "…", "at": "…"}]
            $table->timestamp('at');
            $table->timestamps();

            $table->index(['automation_id', 'at'], 'sd_automation_runs_automation_at_idx');
            $table->index('at', 'sd_automation_runs_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_automation_runs');
        Schema::dropIfExists('security_device_automations');
    }
};
