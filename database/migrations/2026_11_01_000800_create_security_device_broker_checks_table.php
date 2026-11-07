<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase E (D21): each hub's daily check that Oblivion's public address refuses MQTT on
 * ports 1883 and 8883. Setup › Sensor broker shows the latest; an answer alerts IT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_broker_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collector_id')->constrained('monitoring_collectors')->cascadeOnDelete();
            $table->string('host', 253);
            $table->json('ports'); // {"1883": "refused", "8883": "refused"}
            $table->boolean('passed');
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['checked_at'], 'sd_broker_checks_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_broker_checks');
    }
};
