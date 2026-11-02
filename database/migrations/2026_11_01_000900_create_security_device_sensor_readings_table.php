<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase E: each sensor reading over time (temperature, humidity, CO2, battery …), for the
 * sensor page's graphs and the battery forecast. Kept 13 months, as Setup › Change history says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_sensor_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('metric', 32); // temperature | humidity | co2 | … | battery
            $table->decimal('value', 10, 2);
            $table->timestamp('observed_at');

            $table->index(['device_id', 'metric', 'observed_at'], 'sd_sensor_readings_device_metric_idx');
            $table->index(['observed_at'], 'sd_sensor_readings_observed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_sensor_readings');
    }
};
