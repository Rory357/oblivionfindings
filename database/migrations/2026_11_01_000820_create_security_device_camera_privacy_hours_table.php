<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D rebuild Phase E (cameras, drawing pg-sdr-4c and pop-up 64): a camera's privacy hours. One row
 * per camera with one or more weekly time ranges in NZ wall-clock time, e.g.
 * [{"days": [1, 2, 3, 4, 5, 6, 7], "from": "22:00", "to": "06:00"}]. A range whose "to" is not after
 * its "from" ends the next morning. While a camera is inside its privacy hours Oblivion serves no
 * picture from it at all. Every change is in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_camera_privacy_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('devices')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('ranges');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_camera_privacy_hours');
    }
};
