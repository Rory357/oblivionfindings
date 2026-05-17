<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_meal_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('meal_products')->cascadeOnDelete();
            $table->decimal('current_qty', 14, 4)->default(0);
            $table->string('unit', 24);
            $table->decimal('par_level', 14, 4)->nullable();
            $table->decimal('reorder_level', 14, 4)->nullable();
            $table->string('location_label')->nullable();
            $table->timestamp('last_counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['site_id', 'product_id'], 'smii_site_product_unq');
            $table->index(['tenant_id', 'site_id'], 'smii_tenant_site_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_meal_inventory_items');
    }
};
