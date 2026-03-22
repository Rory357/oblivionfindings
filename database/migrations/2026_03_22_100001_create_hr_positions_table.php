<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->string('code');
            $table->string('department')->nullable();
            $table->string('team')->nullable();
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->string('employment_type')->default('full_time'); // full_time, part_time, casual, fixed_term
            $table->decimal('fte', 3, 2)->default(1.00);
            $table->unsignedInteger('headcount_budget')->default(1);
            $table->unsignedInteger('current_headcount')->default(0);
            $table->foreignId('reports_to_position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'department']);
        });

        // Add position_id to hr_employee_profiles
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('position_role')->constrained('hr_positions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
        Schema::dropIfExists('hr_positions');
    }
};
