<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resident journeys record one pre-transport safety check each. The journey
     * service refuses the check while this table is missing, and no earlier
     * migration created it, so every submission was being refused.
     */
    public function up(): void
    {
        if (Schema::hasTable('fleet_transport_pre_checks')) {
            return;
        }

        Schema::create('fleet_transport_pre_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_id')->unique()->constrained('fleet_resident_transports')->restrictOnDelete();
            $table->json('checks');
            $table->foreignId('completed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('fleet_transport_pre_checks')->exists()) {
            throw new RuntimeException('Pre-transport safety checks exist; rollback requires an evidence retention plan.');
        }
        Schema::dropIfExists('fleet_transport_pre_checks');
    }
};
