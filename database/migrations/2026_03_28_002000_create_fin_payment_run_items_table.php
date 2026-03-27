<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_payment_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_run_id')->constrained('fin_payment_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->foreignId('vendor_id')->constrained('fin_vendors');
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payment_run_items');
    }
};
