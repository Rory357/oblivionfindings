<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_geofence_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('geofence_id')->constrained('asset_geofences')->cascadeOnDelete();
            $table->string('status')->default('outside'); // inside|outside
            $table->dateTime('last_changed_at')->nullable();
            $table->dateTime('last_inside_at')->nullable();
            $table->dateTime('last_outside_at')->nullable();
            $table->dateTime('dwell_started_at')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'geofence_id']);
            $table->index(['status', 'last_changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_geofence_states');
    }
};
