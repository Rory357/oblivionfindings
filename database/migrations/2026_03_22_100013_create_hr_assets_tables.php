<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Assets
        Schema::create('hr_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('asset_tag');
            $table->string('name');
            $table->string('category'); // laptop, phone, tablet, vehicle, key, card, uniform, other
            $table->string('serial_number')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 10, 2)->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->string('status')->default('available'); // available, assigned, maintenance, retired
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'asset_tag']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category']);
        });

        // Asset assignments
        Schema::create('hr_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('asset_id')->constrained('hr_assets')->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->datetime('assigned_at');
            $table->datetime('returned_at')->nullable();
            $table->string('condition_on_assign')->nullable();
            $table->string('condition_on_return')->nullable();
            $table->foreignId('assigned_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'employee_profile_id'], 'hr_asset_assign_tenant_emp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_asset_assignments');
        Schema::dropIfExists('hr_assets');
    }
};
