<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('fin_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts');
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('funding_stream_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['account_id']);
            $table->index(['journal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journal_lines');
    }
};
