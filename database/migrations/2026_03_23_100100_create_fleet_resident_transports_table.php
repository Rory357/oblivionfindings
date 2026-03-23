<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_resident_transports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('booking_id')->nullable()->constrained('fleet_vehicle_bookings')->nullOnDelete();
            $table->foreignId('driver_user_id')->constrained('users');
            $table->foreignId('resident_id')->nullable();
            $table->string('resident_name');
            $table->string('transport_type'); // medical, respite, community, shopping, appointment, social, other
            $table->string('pickup_location')->nullable();
            $table->string('dropoff_location')->nullable();
            $table->timestamp('departed_at');
            $table->timestamp('arrived_at')->nullable();
            $table->integer('passengers_count')->default(1);
            $table->string('supervisor_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('in_progress'); // in_progress, completed, cancelled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_resident_transports');
    }
};
