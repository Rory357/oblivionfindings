<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('field_key'); // unique per tenant
            $table->string('field_type'); // text, number, date, select, checkbox, textarea
            $table->json('options')->nullable(); // for select type — array of option values
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'field_key']);
        });

        Schema::create('hr_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('field_definition_id')->constrained('hr_custom_field_definitions')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['employee_profile_id', 'field_definition_id'], 'hr_cfv_profile_definition_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_custom_field_values');
        Schema::dropIfExists('hr_custom_field_definitions');
    }
};
