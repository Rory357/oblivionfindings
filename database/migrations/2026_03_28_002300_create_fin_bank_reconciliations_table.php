<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('bank_account_id')->constrained('fin_bank_accounts');
            $table->date('statement_date');
            $table->decimal('statement_balance', 14, 2);
            $table->decimal('calculated_balance', 14, 2)->nullable();
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_reconciliations');
    }
};
