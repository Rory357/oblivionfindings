<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts');
            $table->date('transaction_date');
            $table->decimal('amount', 14, 2);
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->string('payee')->nullable();
            $table->enum('source', ['manual', 'import', 'feed'])->default('manual');
            $table->unsignedBigInteger('reconciliation_id')->nullable();
            $table->unsignedBigInteger('matched_journal_line_id')->nullable();
            $table->enum('status', ['unreconciled', 'matched', 'reconciled'])->default('unreconciled');
            $table->timestamps();

            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_transactions');
    }
};
