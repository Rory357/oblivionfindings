<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_outings') || !Schema::hasTable('organisations')) {
            return;
        }
        Schema::create('fleet_outings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('destination');
            $table->string('purpose')->nullable();
            $table->timestamp('planned_departure');
            $table->timestamp('planned_return');
            $table->timestamp('actual_departure')->nullable();
            $table->timestamp('actual_return')->nullable();
            $table->foreignId('asset_id')->nullable()->constrained('assets');
            $table->foreignId('driver_user_id')->nullable()->constrained('users');
            $table->foreignId('booking_id')->nullable()->constrained('fleet_vehicle_bookings')->nullOnDelete();
            $table->json('risk_assessment')->nullable();
            $table->string('status')->default('planned');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('fleet_outing_residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outing_id')->constrained('fleet_outings')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients');
            $table->boolean('pre_check_completed')->default(false);
            $table->boolean('medication_packed')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_outing_residents');
        Schema::dropIfExists('fleet_outings');
    }
};
