<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the orphaned hr_dashboard_configs table. The HrDashboardConfig model was
 * never referenced by any controller, route, service, seeder, factory, or test —
 * a fully standalone leaf. Reversible: down() recreates the original schema.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('hr_dashboard_configs');
    }

    public function down(): void
    {
        Schema::create('hr_dashboard_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('layout')->nullable();
            $table->timestamps();
        });
    }
};
