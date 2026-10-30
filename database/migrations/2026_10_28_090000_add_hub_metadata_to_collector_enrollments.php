<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->string('intended_name', 120)->nullable();
            $table->json('intended_covered_site_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->dropColumn(['intended_name', 'intended_covered_site_ids']);
        });
    }
};
