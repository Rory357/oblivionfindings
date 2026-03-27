<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_gst_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gst_return_id')->constrained('fin_gst_returns')->cascadeOnDelete();
            $table->unsignedBigInteger('journal_line_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('net_amount', 14, 2);
            $table->decimal('gst_amount', 14, 2);
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_gst_return_lines');
    }
};
