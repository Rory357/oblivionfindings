<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'clock_in_latitude')) {
                $table->decimal('clock_in_latitude', 10, 7)->nullable();
                $table->decimal('clock_in_longitude', 10, 7)->nullable();
                $table->decimal('clock_out_latitude', 10, 7)->nullable();
                $table->decimal('clock_out_longitude', 10, 7)->nullable();
                $table->string('clock_in_address')->nullable();
                $table->string('clock_out_address')->nullable();
                $table->boolean('geofence_verified')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'clock_in_latitude',
                'clock_in_longitude',
                'clock_out_latitude',
                'clock_out_longitude',
                'clock_in_address',
                'clock_out_address',
                'geofence_verified',
            ]);
        });
    }
};
