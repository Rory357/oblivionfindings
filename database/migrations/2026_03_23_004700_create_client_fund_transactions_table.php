<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_fund_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('client_fund_id')->constrained('client_funds')->cascadeOnDelete();
            $table->string('transaction_type');
            $table->decimal('amount', 12, 2);
            $table->decimal('running_balance', 12, 2);
            $table->string('description');
            $table->string('reference')->nullable();
            $table->string('category')->nullable();
            $table->date('transaction_date');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamps();

            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_fund_transactions');
    }
};
