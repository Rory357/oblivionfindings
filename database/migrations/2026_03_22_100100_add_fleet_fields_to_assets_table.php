<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('home_site_id')->nullable()->after('site_id')->constrained('sites')->nullOnDelete();
            $table->foreignId('primary_driver_user_id')->nullable()->after('home_site_id')->constrained('users')->nullOnDelete();
            $table->string('registration_number')->nullable()->after('serial_number');
            $table->date('registration_expires_at')->nullable()->after('registration_number');
            $table->date('wof_expires_at')->nullable()->after('registration_expires_at');
            $table->date('cof_expires_at')->nullable()->after('wof_expires_at');
            $table->string('fuel_type')->nullable()->after('cof_expires_at');
            $table->decimal('odometer_km', 10, 1)->nullable()->after('fuel_type');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['home_site_id']);
            $table->dropForeign(['primary_driver_user_id']);
            $table->dropColumn([
                'home_site_id',
                'primary_driver_user_id',
                'registration_number',
                'registration_expires_at',
                'wof_expires_at',
                'cof_expires_at',
                'fuel_type',
                'odometer_km',
            ]);
        });
    }
};
