<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links departments to the sites they operate across (many-to-many). Employees
 * already carry primary_site_id; this captures the organisational footprint of a
 * department (e.g. "Care Services" runs at Kauri House + Rata House).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_department_site')) {
            return;
        }

        Schema::create('hr_department_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_department_id')->constrained('hr_departments')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['hr_department_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_department_site');
    }
};
