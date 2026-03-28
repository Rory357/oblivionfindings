<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_representatives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('work_group')->nullable();
            $table->string('election_method');
            $table->date('elected_at');
            $table->date('term_expires_at')->nullable();
            $table->string('status')->default('active');
            $table->integer('training_days_completed')->default(0);
            $table->date('initial_training_completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('site_id');
        });

        Schema::create('hs_committees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('meeting_frequency')->default('monthly');
            $table->text('terms_of_reference')->nullable();
            $table->date('established_at');
            $table->string('status')->default('active');
            $table->json('members')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
        });

        Schema::create('hs_committee_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hs_committee_id');
            $table->dateTime('scheduled_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->default('scheduled');
            $table->json('attendees')->nullable();
            $table->json('agenda_items')->nullable();
            $table->text('minutes')->nullable();
            $table->json('action_items')->nullable();
            $table->text('safety_concerns_raised')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hs_committee_id')->references('id')->on('hs_committees')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('scheduled_at');
        });

        Schema::create('hs_consultations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('consultation_type');
            $table->text('description');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('initiated_by');
            $table->date('consultation_date');
            $table->json('workers_consulted')->nullable();
            $table->text('worker_feedback_summary')->nullable();
            $table->text('outcome')->nullable();
            $table->text('changes_made')->nullable();
            $table->string('status')->default('open');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('initiated_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('consultation_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_consultations');
        Schema::dropIfExists('hs_committee_meetings');
        Schema::dropIfExists('hs_committees');
        Schema::dropIfExists('hs_representatives');
    }
};
