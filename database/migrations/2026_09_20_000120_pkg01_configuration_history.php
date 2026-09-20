<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_maintenance_site_route_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('coordinator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('backup_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('approval_reference', 150);
            $table->dateTime('approved_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['site_id', 'version'], 'fleet_msrh_site_version_uq');
        });
        Schema::create('fleet_maintenance_configuration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('configuration_kind', 32);
            $table->string('asset_category', 64)->nullable();
            $table->string('rule_kind', 32)->nullable();
            $table->unsignedInteger('version');
            $table->string('approval_reference', 150);
            $table->char('input_sha256', 64);
            $table->json('result_json');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['site_id', 'configuration_kind', 'created_at'], 'fleet_mce_site_kind_time_idx');
        });
    }

    public function down(): void
    {
        \App\Services\Fleet\MaintenanceRollbackGuard::assertEmpty();
        foreach (['fleet_maintenance_configuration_events', 'fleet_maintenance_site_route_history'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('PKG-01 configuration history exists; preserve it.');
            }
        }
        Schema::dropIfExists('fleet_maintenance_configuration_events');
        Schema::dropIfExists('fleet_maintenance_site_route_history');
    }
};
