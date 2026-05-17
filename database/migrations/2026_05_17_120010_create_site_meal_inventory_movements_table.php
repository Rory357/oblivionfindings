<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_meal_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('meal_products')->cascadeOnDelete();
            $table->decimal('delta', 14, 4);
            $table->string('unit', 24);
            $table->enum('reason', [
                'stocktake',
                'delivery',
                'consumption',
                'waste',
                'adjustment',
                'plan_consumption',
            ]);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['site_id', 'product_id', 'performed_at'], 'smim_site_product_at_idx');
            $table->index(['reference_type', 'reference_id'], 'smim_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_meal_inventory_movements');
    }
};
