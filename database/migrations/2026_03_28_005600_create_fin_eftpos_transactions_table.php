<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_eftpos_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('fin_eftpos_batches')->cascadeOnDelete();
            $table->string('transaction_reference');
            $table->datetime('transaction_date');
            $table->enum('card_type', ['visa', 'mastercard', 'eftpos', 'amex', 'other'])->default('eftpos');
            $table->enum('transaction_type', ['purchase', 'refund', 'cash_out'])->default('purchase');
            $table->decimal('amount', 14, 2);
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->string('auth_code')->nullable();
            $table->char('card_last_four', 4)->nullable();
            $table->enum('status', ['approved', 'declined', 'voided'])->default('approved');
            $table->timestamps();

            $table->index(['batch_id', 'transaction_date'], 'fin_eftpos_txn_batch_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_eftpos_transactions');
    }
};
