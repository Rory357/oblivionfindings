<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_drills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('drill_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('scheduled_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->integer('evacuation_time_seconds')->nullable();
            $table->string('status')->default('scheduled');
            $table->string('outcome')->nullable();
            $table->text('scenario_description')->nullable();
            $table->integer('total_participants')->nullable();
            $table->integer('residents_evacuated')->nullable();
            $table->boolean('all_areas_checked')->default(false);
            $table->boolean('assembly_point_reached')->default(false);
            $table->boolean('roll_call_completed')->default(false);
            $table->text('weather_conditions')->nullable();
            $table->text('observer_notes')->nullable();
            $table->text('improvements_identified')->nullable();
            $table->unsignedBigInteger('conducted_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('conducted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('scheduled_at');
            $table->index('site_id');
        });

        Schema::create('emergency_drill_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('emergency_drill_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->nullable();
            $table->boolean('attended')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('emergency_drill_id')->references('id')->on('emergency_drills')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['emergency_drill_id', 'user_id']);
        });

        Schema::create('emergency_drill_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('emergency_drill_id');
            $table->string('finding_type');
            $table->text('description');
            $table->string('severity')->nullable();
            $table->string('status')->default('open');
            $table->text('corrective_action')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->date('due_date')->nullable();
            $table->date('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('emergency_drill_id')->references('id')->on('emergency_drills')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_drill_findings');
        Schema::dropIfExists('emergency_drill_participants');
        Schema::dropIfExists('emergency_drills');
    }
};
