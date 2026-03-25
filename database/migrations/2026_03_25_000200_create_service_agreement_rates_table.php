<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_agreement_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('service_agreement_id')->constrained('service_agreements')->cascadeOnDelete();
            $table->string('rate_type'); // weekday, evening, weekend, public_holiday, sleepover, active_night, overtime, travel, mileage
            $table->decimal('rate', 8, 2);
            $table->string('unit')->default('hour'); // hour, night, km, trip, flat
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_agreement_rates');
    }
};
