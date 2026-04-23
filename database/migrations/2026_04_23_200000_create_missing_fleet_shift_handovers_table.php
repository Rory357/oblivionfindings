<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_shift_handovers')) {
            return;
        }

        Schema::create('fleet_shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('outgoing_user_id')->constrained('users');
            $table->foreignId('incoming_user_id')->nullable()->constrained('users');
            $table->integer('odometer_km')->nullable();
            $table->string('fuel_level')->nullable();
            $table->string('exterior_condition');
            $table->string('interior_condition');
            $table->boolean('keys_present')->default(true);
            $table->boolean('documents_present')->default(true);
            $table->boolean('first_aid_kit')->default(true);
            $table->boolean('fire_extinguisher')->default(true);
            $table->json('damage_notes')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending_acceptance');
            $table->timestamp('handed_over_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_shift_handovers');
    }
};
