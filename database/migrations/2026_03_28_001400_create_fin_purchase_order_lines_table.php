<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('fin_purchase_orders')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('gst_rate', 5, 4)->default(0.1500);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->decimal('received_quantity', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_purchase_order_lines');
    }
};
