<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppe_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->text('hazards_addressed')->nullable();
            $table->string('standards_reference')->nullable();
            $table->string('inspection_frequency')->nullable();
            $table->integer('typical_lifespan_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('is_active');
        });

        Schema::create('ppe_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ppe_type_id');
            $table->unsignedBigInteger('site_id');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('condition')->default('good');
            $table->integer('quantity')->default(1);
            $table->string('location')->nullable();
            $table->string('status')->default('available');
            $table->date('last_inspected_at')->nullable();
            $table->date('next_inspection_due')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ppe_type_id')->references('id')->on('ppe_types')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('ppe_type_id');
            $table->index('site_id');
            $table->index('status');
            $table->index('next_inspection_due');
        });

        Schema::create('ppe_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ppe_inventory_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('allocated_at');
            $table->dateTime('returned_at')->nullable();
            $table->boolean('fit_test_completed')->default(false);
            $table->date('fit_test_date')->nullable();
            $table->string('fit_test_result')->nullable();
            $table->boolean('training_completed')->default(false);
            $table->date('training_date')->nullable();
            $table->boolean('acknowledged')->default(false);
            $table->dateTime('acknowledged_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ppe_inventory_id')->references('id')->on('ppe_inventory')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('ppe_inventory_id');
            $table->index('user_id');
        });

        Schema::create('ppe_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ppe_inventory_id');
            $table->unsignedBigInteger('inspected_by');
            $table->dateTime('inspected_at');
            $table->string('result');
            $table->string('condition_after')->nullable();
            $table->text('findings')->nullable();
            $table->text('action_taken')->nullable();
            $table->date('next_inspection_due')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ppe_inventory_id')->references('id')->on('ppe_inventory')->cascadeOnDelete();
            $table->foreign('inspected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('ppe_inventory_id');
            $table->index('inspected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_inspections');
        Schema::dropIfExists('ppe_allocations');
        Schema::dropIfExists('ppe_inventory');
        Schema::dropIfExists('ppe_types');
    }
};
