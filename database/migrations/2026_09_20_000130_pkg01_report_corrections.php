<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_maintenance_reports', function (Blueprint $table): void {
            $table->foreignId('corrects_report_id')->nullable()->after('source_id')
                ->constrained('fleet_maintenance_reports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        \App\Services\Fleet\MaintenanceRollbackGuard::assertEmpty();
        Schema::table('fleet_maintenance_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corrects_report_id');
        });
    }
};
