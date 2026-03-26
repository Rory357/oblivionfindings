<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_refusal_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('client_medication_administration_id');
            $table->foreign('client_medication_administration_id', 'med_refusal_admin_id_fk')->references('id')->on('client_medication_administrations')->cascadeOnDelete();

            $table->enum('reason_category', [
                'personal_choice',
                'side_effects',
                'difficulty_swallowing',
                'nausea',
                'pain',
                'cognitive',
                'behavioural',
                'sleeping',
                'other',
            ]);
            $table->text('detailed_reason')->nullable();

            $table->enum('client_capacity_at_time', [
                'has_capacity',
                'lacks_capacity',
                'fluctuating',
                'not_assessed',
            ])->default('not_assessed');

            $table->boolean('offered_alternative')->default(false);
            $table->text('alternative_details')->nullable();

            $table->boolean('gp_notification_required')->default(false);
            $table->timestamp('gp_notified_at')->nullable();
            $table->foreignId('gp_notified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('gp_response')->nullable();

            $table->boolean('family_notified')->default(false);
            $table->timestamp('family_notified_at')->nullable();

            $table->text('follow_up_action')->nullable();
            $table->timestamp('follow_up_due_at')->nullable();
            $table->timestamp('follow_up_completed_at')->nullable();
            $table->foreignId('follow_up_completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('escalated_to_manager')->default(false);
            $table->timestamp('escalated_at')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_refusal_followups');
    }
};
