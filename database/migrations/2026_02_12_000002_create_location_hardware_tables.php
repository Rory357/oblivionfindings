<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Site Rooms: Lightweight room registry for hardware mapping ──
        Schema::create('site_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->string('linked_room_type')->nullable(); // house_room / ho_resource / facility_zone
            $table->unsignedBigInteger('linked_room_id')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'name']);
        });

        // ── Location Hardware: Vendor-neutral hardware registry ──
        Schema::create('location_hardware', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('site_rooms')->nullOnDelete();
            $table->string('provider'); // unifi / queclink / manual / iot / hikvision
            $table->string('category'); // gateway / switch / ap / camera / door / sensor / nvr / ai / tracker / other
            $table->string('name');
            $table->string('asset_tag')->nullable();
            $table->string('serial')->nullable();
            $table->string('mac')->nullable();
            $table->string('status')->default('unknown'); // online / offline / unknown / retired
            $table->dateTime('last_seen_at')->nullable();
            $table->json('external_ref')->nullable(); // { provider_entity_id, controller_id, firmware_version, model, etc. }
            $table->foreignId('linked_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('linked_person_type')->nullable(); // staff / client
            $table->unsignedBigInteger('linked_person_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'category']);
            $table->index(['provider', 'status']);
            $table->index(['linked_asset_id']);
            $table->index(['site_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_hardware');
        Schema::dropIfExists('site_rooms');
    }
};
