<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_portal_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->boolean('show_shift_schedule')->default(true);
            $table->boolean('show_care_notes')->default(true);
            $table->boolean('show_care_plans')->default(false);
            $table->boolean('show_medication_status')->default(false);
            $table->boolean('show_incidents')->default(false);
            $table->boolean('notify_shift_arrival')->default(true);
            $table->boolean('notify_shift_completion')->default(true);
            $table->boolean('notify_incident')->default(true);
            $table->timestamps();

            $table->unique('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_portal_settings');
    }
};
