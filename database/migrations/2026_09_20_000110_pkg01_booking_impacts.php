<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_maintenance_booking_impacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('restriction_id')->constrained('fleet_maintenance_restrictions')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('fleet_vehicle_bookings')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_action_id')->constrained('fleet_maintenance_actions')->restrictOnDelete();
            $table->foreignId('release_action_id')->nullable()->constrained('fleet_maintenance_actions')->restrictOnDelete();
            $table->foreignId('review_action_id')->nullable()->constrained('fleet_maintenance_actions')->restrictOnDelete();
            $table->string('followup_state', 24)->default('needs_review');
            $table->dateTime('source_released_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['restriction_id', 'booking_id'], 'fleet_mbi_restriction_booking_uq');
            $table->index(['work_order_id', 'followup_state'], 'fleet_mbi_work_state_idx');
            $table->index(['booking_id', 'followup_state'], 'fleet_mbi_booking_state_idx');
        });
    }

    public function down(): void
    {
        \App\Services\Fleet\MaintenanceRollbackGuard::assertEmpty();
        if (Schema::hasTable('fleet_maintenance_booking_impacts')
            && DB::table('fleet_maintenance_booking_impacts')->exists()) {
            throw new RuntimeException('PKG-01 booking impact evidence exists; preserve it.');
        }
        Schema::dropIfExists('fleet_maintenance_booking_impacts');
    }
};
