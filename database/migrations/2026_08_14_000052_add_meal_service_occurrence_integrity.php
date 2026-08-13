<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_meal_plan_entries', function (Blueprint $table): void {
            $table->unsignedInteger('meal_service_sequence')->default(0)->after('served_by');
            $table->unsignedSmallInteger('meal_service_movement_count')->default(0)->after('meal_service_sequence');
        });

        Schema::table('site_meal_inventory_movements', function (Blueprint $table): void {
            $table->dropForeign('site_meal_inventory_movements_site_id_foreign');
            $table->dropForeign('site_meal_inventory_movements_product_id_foreign');

            $table->string('meal_service_key', 120)->nullable()->after('reference_id');
            $table->enum('meal_service_action', ['serve', 'unserve'])->nullable()->after('meal_service_key');
            $table->unsignedBigInteger('meal_recipe_id')->nullable()->after('meal_service_action');
            $table->json('meal_recipe_ingredient_ids')->nullable()->after('meal_recipe_id');
            $table->unsignedBigInteger('reversal_of_id')->nullable()->after('meal_recipe_ingredient_ids');

            $table->unique(
                ['meal_service_key', 'product_id', 'meal_service_action'],
                'smim_meal_service_action_unique',
            );
            $table->unique('reversal_of_id', 'smim_reversal_unique');
            $table->foreign('reversal_of_id', 'smim_reversal_fk')
                ->references('id')
                ->on('site_meal_inventory_movements')
                ->restrictOnDelete();
            $table->index('meal_recipe_id', 'smim_meal_recipe_idx');
            $table->foreign('site_id', 'site_meal_inventory_movements_site_id_foreign')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->foreign('product_id', 'site_meal_inventory_movements_product_id_foreign')
                ->references('id')
                ->on('meal_products')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_meal_inventory_movements', function (Blueprint $table): void {
            $table->dropForeign('smim_reversal_fk');
            $table->dropForeign('site_meal_inventory_movements_site_id_foreign');
            $table->dropForeign('site_meal_inventory_movements_product_id_foreign');
            $table->dropUnique('smim_reversal_unique');
            $table->dropUnique('smim_meal_service_action_unique');
            $table->dropIndex('smim_meal_recipe_idx');
            $table->dropColumn([
                'meal_service_key',
                'meal_service_action',
                'meal_recipe_id',
                'meal_recipe_ingredient_ids',
                'reversal_of_id',
            ]);
            $table->foreign('site_id', 'site_meal_inventory_movements_site_id_foreign')
                ->references('id')
                ->on('sites')
                ->cascadeOnDelete();
            $table->foreign('product_id', 'site_meal_inventory_movements_product_id_foreign')
                ->references('id')
                ->on('meal_products')
                ->cascadeOnDelete();
        });

        Schema::table('site_meal_plan_entries', function (Blueprint $table): void {
            $table->dropColumn(['meal_service_sequence', 'meal_service_movement_count']);
        });
    }
};
