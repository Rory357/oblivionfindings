<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('asset_name');
            $table->string('asset_tag')->nullable();
            $table->enum('category', ['vehicle', 'equipment', 'building', 'furniture', 'it_equipment', 'land']);
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 14, 2);
            $table->decimal('residual_value', 14, 2)->default(0);
            $table->integer('useful_life_months');
            $table->enum('depreciation_method', ['straight_line', 'diminishing_value']);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->unsignedBigInteger('gl_asset_account_id')->nullable();
            $table->unsignedBigInteger('gl_depreciation_account_id')->nullable();
            $table->unsignedBigInteger('gl_expense_account_id')->nullable();
            $table->enum('status', ['active', 'disposed', 'fully_depreciated'])->default('active');
            $table->date('disposed_date')->nullable();
            $table->decimal('disposal_proceeds', 14, 2)->nullable();
            $table->unsignedBigInteger('linked_asset_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_fixed_assets');
    }
};
