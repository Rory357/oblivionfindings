<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_cash_flow_forecasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('name');
            $table->date('forecast_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('period_type', ['weekly', 'fortnightly', 'monthly']);
            $table->decimal('opening_balance', 14, 2);
            $table->json('forecast_data');
            $table->json('assumptions')->nullable();
            $table->enum('status', ['draft', 'final']);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_cash_flow_forecasts');
    }
};
