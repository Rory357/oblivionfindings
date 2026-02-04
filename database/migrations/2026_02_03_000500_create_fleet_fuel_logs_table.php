<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('logged_at');
            $table->string('fuel_type', 50)->default('petrol'); // petrol, diesel, electric, hybrid, lpg
            $table->decimal('quantity_litres', 8, 2);
            $table->decimal('cost_per_litre', 8, 3)->nullable();
            $table->decimal('total_cost', 10, 2);
            $table->decimal('odometer_km', 12, 1)->nullable();
            $table->boolean('full_tank')->default(false);
            $table->string('station_name', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'logged_at']);
            $table->index('logged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_fuel_logs');
    }
};
