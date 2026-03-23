<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedBigInteger('price_book_item_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->string('unit')->default('hour');
            $table->decimal('unit_price', 8, 2);
            $table->decimal('amount', 12, 2);
            $table->string('service_code')->nullable();
            $table->timestamps();

            $table->foreign('price_book_item_id')->references('id')->on('price_book_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_line_items');
    }
};
