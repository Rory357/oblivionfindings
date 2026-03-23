<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_agreement_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('service_agreement_id')->constrained('service_agreements')->cascadeOnDelete();
            $table->string('item_number')->nullable();
            $table->string('description');
            $table->decimal('unit_price', 8, 2);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit')->default('hour');
            $table->decimal('budget_allocated', 12, 2)->default(0);
            $table->decimal('budget_used', 12, 2)->default(0);
            $table->string('category')->nullable();
            $table->string('ndis_line_item_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_agreement_line_items');
    }
};
