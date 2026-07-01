<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 1 (additive slice) of the Training Hub redesign handover: hang the rich
 * course metadata the Create/Edit-course wizard collects onto the canonical
 * `hr_courses` catalog entity. All columns are nullable / defaulted so the
 * migration is a pure additive (safe rollback = drop columns).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_courses', function (Blueprint $table) {
            $table->text('learning_outcomes')->nullable()->after('description');
            $table->json('prerequisites')->nullable()->after('learning_outcomes');
            $table->boolean('requires_renewal')->default(false)->after('is_mandatory');
            $table->unsignedSmallInteger('validity_period_months')->nullable()->after('requires_renewal');
            $table->unsignedSmallInteger('renewal_reminder_months')->nullable()->after('validity_period_months');
            $table->boolean('requires_assessment')->default(false)->after('renewal_reminder_months');
            $table->unsignedTinyInteger('pass_mark_percentage')->nullable()->after('requires_assessment');
            $table->unsignedSmallInteger('cpd_points')->nullable()->after('pass_mark_percentage');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->json('mandatory_for_roles')->nullable()->after('cpd_points');
            // Fee routing flags surfaced in the wizard's Fee & finance step.
            $table->boolean('org_pays_provider')->default(false)->after('cost');
            $table->boolean('staff_can_claim')->default(false)->after('org_pays_provider');
        });
    }

    public function down(): void
    {
        Schema::table('hr_courses', function (Blueprint $table) {
            $table->dropColumn([
                'learning_outcomes',
                'prerequisites',
                'requires_renewal',
                'validity_period_months',
                'renewal_reminder_months',
                'requires_assessment',
                'pass_mark_percentage',
                'cpd_points',
                'provider_reference',
                'mandatory_for_roles',
                'org_pays_provider',
                'staff_can_claim',
            ]);
        });
    }
};
