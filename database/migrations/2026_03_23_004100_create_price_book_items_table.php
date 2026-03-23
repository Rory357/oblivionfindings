<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_book_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('price_book_id')->constrained('price_books')->cascadeOnDelete();
            $table->string('service_code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->default('hour');
            $table->decimal('rate', 8, 2);
            $table->string('rate_type')->default('standard');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['price_book_id', 'service_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_book_items');
    }
};
