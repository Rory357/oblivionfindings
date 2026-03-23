<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('outgoing_user_id')->constrained('users');
            $table->foreignId('incoming_user_id')->nullable()->constrained('users');
            $table->integer('odometer_km')->nullable();
            $table->string('fuel_level')->nullable(); // full, 3/4, 1/2, 1/4, empty
            $table->string('exterior_condition'); // good, minor_damage, significant_damage
            $table->string('interior_condition'); // clean, acceptable, needs_cleaning
            $table->boolean('keys_present')->default(true);
            $table->boolean('documents_present')->default(true);
            $table->boolean('first_aid_kit')->default(true);
            $table->boolean('fire_extinguisher')->default(true);
            $table->json('damage_notes')->nullable(); // array of {area, description}
            $table->text('notes')->nullable();
            $table->string('status')->default('pending_acceptance'); // pending_acceptance, accepted, disputed
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
