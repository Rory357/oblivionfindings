<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_aid_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('treated_person_id')->nullable();
            $table->string('treated_person_name');
            $table->string('treated_person_type'); // staff, client, visitor, contractor
            $table->dateTime('treatment_date');
            $table->string('injury_illness_type'); // cut, burn, sprain, fall, allergic_reaction, breathing_difficulty, chest_pain, seizure, fainting, other
            $table->text('injury_illness_description');
            $table->string('body_part')->nullable();
            $table->text('treatment_given');
            $table->string('treatment_outcome'); // returned_to_work, sent_home, sent_to_medical, sent_to_hospital, refused_treatment
            $table->boolean('ambulance_called')->default(false);
            $table->unsignedBigInteger('first_aider_id');
            $table->text('first_aider_notes')->nullable();
            $table->boolean('incident_reported')->default(false);
            $table->unsignedBigInteger('related_incident_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('treated_person_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('first_aider_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('related_incident_id')->references('id')->on('client_incidents')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('site_id');
            $table->index('treatment_date');
            $table->index('treated_person_type');
            $table->index('treatment_outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_aid_records');
    }
};
