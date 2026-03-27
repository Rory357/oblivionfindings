<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_cash_flow_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('fin_cash_flow_forecasts')->cascadeOnDelete();
            $table->string('name');
            $table->json('adjustments');
            $table->json('forecast_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_cash_flow_scenarios');
    }
};
