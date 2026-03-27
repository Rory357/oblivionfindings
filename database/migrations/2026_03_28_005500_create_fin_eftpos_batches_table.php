<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_eftpos_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('terminal_id')->constrained('fin_eftpos_terminals');
            $table->string('batch_number');
            $table->date('batch_date');
            $table->date('settlement_date')->nullable();
            $table->integer('total_transactions')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('total_refunds', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->decimal('fees', 14, 2)->default(0);
            $table->decimal('settlement_amount', 14, 2)->default(0);
            $table->enum('status', ['open', 'closed', 'reconciled', 'discrepancy'])->default('open');
            $table->foreignId('bank_transaction_id')->nullable()->constrained('fin_bank_transactions')->nullOnDelete();
            $table->datetime('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('discrepancy_amount', 14, 2)->default(0);
            $table->text('discrepancy_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'batch_date'], 'fin_eftpos_batch_org_stat_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_eftpos_batches');
    }
};
