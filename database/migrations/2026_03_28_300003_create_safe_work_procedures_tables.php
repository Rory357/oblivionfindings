<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_work_procedures', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('reference_number')->unique();
            $table->string('category'); // manual_handling, infection_control, medication, chemical_handling, fire_safety, vehicle_operation, personal_care, challenging_behaviour, lone_working, equipment_use, other
            $table->text('purpose')->nullable();
            $table->text('scope')->nullable();
            $table->json('hazards_addressed')->nullable();
            $table->json('ppe_required')->nullable();
            $table->json('steps')->nullable();
            $table->json('emergency_procedures')->nullable();
            $table->string('status')->default('draft'); // draft, under_review, approved, archived
            $table->integer('current_version')->default(1);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->date('review_date')->nullable();
            $table->json('applicable_roles')->nullable();
            $table->json('applicable_sites')->nullable();
            $table->json('related_training')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('category');
            $table->index('status');
            $table->index('review_date');
        });

        Schema::create('safe_work_procedure_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('safe_work_procedure_id');
            $table->integer('version_number');
            $table->json('content_snapshot')->nullable();
            $table->text('change_summary')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->foreign('safe_work_procedure_id')->references('id')->on('safe_work_procedures')->cascadeOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();

            $table->index('safe_work_procedure_id');
            $table->unique(['safe_work_procedure_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_work_procedure_versions');
        Schema::dropIfExists('safe_work_procedures');
    }
};
