<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase F (drawing pg-sdr-12c, pop-ups 42 and 43): a Site's house modes (Day, Night, Away…).
 *
 * - A mode has an optional weekly schedule in NZ wall-clock time ({"days": [1..7], "starts": "21:00"})
 *   and a list of safe actions (SafeActions::ACTIONS only, D12). It runs until the next mode starts.
 * - The log keeps every switch and what each action found, plus alarm checks that run a few minutes
 *   after a switch ("armed by 9:15 pm"), which wait as `pending` rows until they are due.
 * - A camera privacy override is "privacy on until a time" that a mode turned on. CameraPrivacy reads
 *   it beside the camera's own privacy hours, so every picture refusal covers it. Nothing ever ends
 *   one early: switching modes never turns camera privacy off.
 *
 * Rooms (pg-sdr-12d) need no table: they are the Site's rooms (site_rooms) and the devices placed in
 * them (device_assignments with assignable_type "room").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_house_modes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('schedule')->nullable(); // {"days": [1, 2, 3, 4, 5, 6, 7], "starts": "21:00"}, or null for by hand only
            $table->json('actions'); // [{"action": "door_locked_check", "doors": [12]}, …]
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'name'], 'sd_house_modes_site_name_unique');
        });

        Schema::create('security_device_house_mode_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('mode_id')->nullable()->constrained('security_device_house_modes')->nullOnDelete();
            $table->string('kind', 16); // switch | check
            $table->string('state', 16)->default('done'); // done | pending | cancelled
            $table->string('source', 16); // schedule | hand
            $table->string('title', 191);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('lines')->nullable(); // [{"tone": "warning", "text": "Back door not locked · staff on shift told"}]
            $table->json('payload')->nullable(); // a pending check's panels and whether to open alerts
            $table->timestamp('at');
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'at'], 'sd_house_mode_events_site_at_idx');
            $table->index(['state', 'due_at'], 'sd_house_mode_events_due_idx');
        });

        Schema::create('security_device_camera_privacy_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->timestamp('until');
            $table->string('source', 24)->default('house_mode');
            $table->foreignId('house_mode_event_id')->nullable()->constrained('security_device_house_mode_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'until'], 'sd_camera_privacy_overrides_device_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_camera_privacy_overrides');
        Schema::dropIfExists('security_device_house_mode_events');
        Schema::dropIfExists('security_device_house_modes');
    }
};
