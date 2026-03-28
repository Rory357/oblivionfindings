<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            // Near-miss fields
            $table->string('potential_severity')->nullable()->after('status'); // low, medium, high, critical
            $table->text('potential_consequence')->nullable()->after('potential_severity');

            // WorkSafe notification fields
            $table->boolean('is_notifiable')->default(false)->after('potential_consequence');
            $table->string('worksafe_notification_status')->nullable()->after('is_notifiable'); // pending, notified, acknowledged
            $table->timestamp('worksafe_notified_at')->nullable()->after('worksafe_notification_status');
            $table->string('worksafe_reference')->nullable()->after('worksafe_notified_at');
            $table->boolean('site_preserved')->default(false)->after('worksafe_reference');
            $table->timestamp('site_preservation_released_at')->nullable()->after('site_preserved');
            $table->string('site_preservation_released_by')->nullable()->after('site_preservation_released_at');

            // Injury details (for WorkSafe)
            $table->string('injured_person_name')->nullable()->after('site_preservation_released_by');
            $table->string('injured_person_role')->nullable()->after('injured_person_name'); // staff, client, visitor, contractor
            $table->integer('injured_person_age')->nullable()->after('injured_person_role');
            $table->string('injury_body_part')->nullable()->after('injured_person_age');
            $table->string('injury_nature')->nullable()->after('injury_body_part'); // fracture, burn, laceration, sprain, etc
            $table->string('injury_classification')->nullable()->after('injury_nature'); // minor, moderate, serious, notifiable
            $table->string('medical_treatment_type')->nullable()->after('injury_classification'); // none, first_aid, medical_centre, hospital, ambulance

            // Investigation fields
            $table->string('investigation_status')->nullable()->after('medical_treatment_type'); // not_required, pending, in_progress, completed
            $table->unsignedBigInteger('investigation_assigned_to')->nullable()->after('investigation_status');
            $table->timestamp('investigation_started_at')->nullable()->after('investigation_assigned_to');
            $table->timestamp('investigation_completed_at')->nullable()->after('investigation_started_at');
            $table->text('root_cause_category')->nullable()->after('investigation_completed_at');
            $table->text('root_cause_description')->nullable()->after('root_cause_category');
            $table->text('contributing_factors')->nullable()->after('root_cause_description');
            $table->json('corrective_actions')->nullable()->after('contributing_factors'); // [{description, assigned_to, due_date, status, completed_at}]
            $table->text('lessons_learned')->nullable()->after('corrective_actions');

            // Foreign key
            $table->foreign('investigation_assigned_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropForeign(['investigation_assigned_to']);

            $table->dropColumn([
                'potential_severity',
                'potential_consequence',
                'is_notifiable',
                'worksafe_notification_status',
                'worksafe_notified_at',
                'worksafe_reference',
                'site_preserved',
                'site_preservation_released_at',
                'site_preservation_released_by',
                'injured_person_name',
                'injured_person_role',
                'injured_person_age',
                'injury_body_part',
                'injury_nature',
                'injury_classification',
                'medical_treatment_type',
                'investigation_status',
                'investigation_assigned_to',
                'investigation_started_at',
                'investigation_completed_at',
                'root_cause_category',
                'root_cause_description',
                'contributing_factors',
                'corrective_actions',
                'lessons_learned',
            ]);
        });
    }
};
