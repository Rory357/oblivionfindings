<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_donor_fund_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained('fin_donor_funds')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->enum('type', ['receipt', 'expenditure', 'commitment', 'release', 'transfer', 'adjustment']);
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('fin_bills')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fund_id', 'transaction_date'], 'fin_donor_fund_txn_fund_date');
            $table->index(['fund_id', 'type'], 'fin_donor_fund_txn_fund_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_donor_fund_transactions');
    }
};
