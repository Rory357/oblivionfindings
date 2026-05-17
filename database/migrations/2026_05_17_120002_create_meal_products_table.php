<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('category', 64)->nullable()->index();
            $table->string('default_unit', 24);
            $table->decimal('pack_size', 12, 4)->nullable();
            $table->string('pack_unit', 24)->nullable();
            $table->unsignedInteger('cost_per_unit_cents')->nullable();
            $table->string('currency', 3)->default('NZD');
            $table->boolean('is_active')->default(true);
            $table->string('barcode', 64)->nullable()->index();
            $table->json('external_refs')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_products');
    }
};
