<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_fx_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('from_currency_id')->constrained('fin_currencies')->cascadeOnDelete();
            $table->foreignId('to_currency_id')->constrained('fin_currencies')->cascadeOnDelete();
            $table->decimal('rate', 14, 6);
            $table->date('effective_date');
            $table->enum('source', ['manual', 'api', 'bank']);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['organization_id', 'from_currency_id', 'to_currency_id', 'effective_date'],
                'fin_fx_rates_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_fx_rates');
    }
};
