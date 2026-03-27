<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fin_fixed_assets')->cascadeOnDelete();
            $table->date('depreciation_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('accumulated_total', 14, 2);
            $table->decimal('book_value_after', 14, 2);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();

            $table->index(['fixed_asset_id', 'depreciation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_fixed_asset_depreciations');
    }
};
