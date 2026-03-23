<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evv_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->dateTime('check_in_time')->nullable();
            $table->dateTime('check_out_time')->nullable();
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->string('check_in_method')->default('gps');
            $table->string('check_out_method')->default('gps');
            $table->string('verification_status')->default('pending');
            $table->boolean('geofence_check_in')->nullable();
            $table->boolean('geofence_check_out')->nullable();
            $table->decimal('distance_from_site_in', 8, 2)->nullable();
            $table->decimal('distance_from_site_out', 8, 2)->nullable();
            $table->string('flagged_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('shift_id');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evv_records');
    }
};
