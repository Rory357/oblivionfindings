<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_petty_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_fund_id')->constrained('fin_petty_cash_funds')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->enum('type', ['top_up', 'expense', 'adjustment']);
            $table->decimal('amount', 14, 2);
            $table->string('description');
            $table->string('receipt_path')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['petty_cash_fund_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_petty_cash_transactions');
    }
};
