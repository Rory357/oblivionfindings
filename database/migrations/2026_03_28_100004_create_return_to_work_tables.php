<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workplace_injuries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('related_incident_id')->nullable();
            $table->dateTime('injury_date');
            $table->string('injury_type');
            $table->string('body_part_affected')->nullable();
            $table->string('severity');
            $table->text('description');
            $table->text('immediate_treatment')->nullable();
            $table->string('medical_treatment_type')->nullable();
            $table->boolean('worksafe_notifiable')->default(false);
            $table->boolean('acc_claim_lodged')->default(false);
            $table->string('acc_claim_number')->nullable();
            $table->integer('lost_time_days')->default(0);
            $table->date('expected_return_date')->nullable();
            $table->date('actual_return_date')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('related_incident_id')->references('id')->on('client_incidents')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('user_id');
            $table->index('injury_date');
        });

        Schema::create('return_to_work_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workplace_injury_id');
            $table->unsignedBigInteger('worker_id');
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->date('plan_start_date');
            $table->date('plan_end_date')->nullable();
            $table->string('status')->default('draft');
            $table->text('medical_clearance_notes')->nullable();
            $table->date('medical_clearance_date')->nullable();
            $table->string('medical_clearance_provider')->nullable();
            $table->json('goals')->nullable();
            $table->json('stages')->nullable();
            $table->text('workplace_modifications')->nullable();
            $table->text('worker_agreement_notes')->nullable();
            $table->boolean('worker_agreed')->default(false);
            $table->date('worker_agreed_at')->nullable();
            $table->date('next_review_date')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workplace_injury_id')->references('id')->on('workplace_injuries')->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
        });

        Schema::create('modified_duties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_to_work_plan_id');
            $table->unsignedBigInteger('user_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('modified_duties_description');
            $table->text('restrictions');
            $table->text('accommodations')->nullable();
            $table->decimal('hours_per_day', 4, 1)->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('return_to_work_plan_id')->references('id')->on('return_to_work_plans')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
        });

        Schema::create('work_capacity_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workplace_injury_id');
            $table->unsignedBigInteger('user_id');
            $table->date('assessment_date');
            $table->string('assessor_name');
            $table->string('assessor_type')->nullable();
            $table->string('capacity_status');
            $table->text('assessment_summary')->nullable();
            $table->text('restrictions')->nullable();
            $table->text('recommendations')->nullable();
            $table->date('next_assessment_date')->nullable();
            $table->string('document_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workplace_injury_id')->references('id')->on('workplace_injuries')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('assessment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_capacity_assessments');
        Schema::dropIfExists('modified_duties');
        Schema::dropIfExists('return_to_work_plans');
        Schema::dropIfExists('workplace_injuries');
    }
};
