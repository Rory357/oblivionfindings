<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('fin_bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->constrained('fin_bank_transactions');
            $table->unsignedBigInteger('journal_line_id')->nullable();
            $table->boolean('is_matched')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_reconciliation_lines');
    }
};
