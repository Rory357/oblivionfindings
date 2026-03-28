<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behaviour_support_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('title');
            $table->text('triggers')->nullable();
            $table->text('de_escalation_strategies')->nullable();
            $table->text('approved_interventions')->nullable();
            $table->text('prohibited_interventions')->nullable();
            $table->string('restrictive_practice_type')->nullable(); // physical, chemical, mechanical, seclusion, environmental
            $table->unsignedBigInteger('developed_by')->nullable();
            $table->date('developed_at')->nullable();
            $table->date('review_date')->nullable();
            $table->string('status')->default('active'); // draft, active, under_review, archived
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('developed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('client_id');
            $table->index('status');
            $table->index('review_date');
        });

        Schema::create('restraint_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('behaviour_support_plan_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->string('restraint_type'); // physical, chemical, mechanical, seclusion, environmental
            $table->string('severity'); // low, medium, high
            $table->text('trigger_description');
            $table->text('de_escalation_attempted');
            $table->text('restraint_description');
            $table->json('staff_involved')->nullable();
            $table->text('person_response')->nullable();
            $table->text('post_incident_support')->nullable();
            $table->boolean('injury_occurred')->default(false);
            $table->text('injury_details')->nullable();
            $table->boolean('within_support_plan')->default(true);
            $table->text('deviation_reason')->nullable();
            $table->unsignedBigInteger('authorised_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->unsignedBigInteger('related_incident_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('behaviour_support_plan_id')->references('id')->on('behaviour_support_plans')->nullOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('authorised_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('related_incident_id')->references('id')->on('client_incidents')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('client_id');
            $table->index('site_id');
            $table->index('started_at');
            $table->index('restraint_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restraint_events');
        Schema::dropIfExists('behaviour_support_plans');
    }
};
