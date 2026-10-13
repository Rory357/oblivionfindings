<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->uuid('expected_collector_uuid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->dropColumn('expected_collector_uuid');
        });
    }
};
