<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_incidents')) {
            return;
        }

        if (! Schema::hasTable('assets') || ! Schema::hasTable('users')) {
            return;
        }

        Schema::create('fleet_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('reported_by_user_id')->constrained('users');
            $table->foreignId('driver_user_id')->nullable()->constrained('users');

            if (Schema::hasTable('fleet_vehicle_bookings')) {
                $table->foreignId('booking_id')->nullable()->constrained('fleet_vehicle_bookings')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('booking_id')->nullable();
            }

            $table->string('incident_type');
            $table->string('severity');
            $table->timestamp('occurred_at');
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('description');
            $table->json('damage_details')->nullable();
            $table->boolean('police_notified')->default(false);
            $table->string('police_reference')->nullable();
            $table->boolean('insurance_claimed')->default(false);
            $table->string('insurance_reference')->nullable();
            $table->string('status')->default('reported');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_incidents');
    }
};
