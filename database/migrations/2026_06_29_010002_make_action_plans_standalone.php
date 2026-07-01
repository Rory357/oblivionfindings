<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An engagement action plan no longer has to live under a survey. It can now
 * originate from a wellbeing flag or stand alone: survey_id becomes nullable and
 * we record source_type (survey | flag | manual) + source_id, plus a direct
 * staff_user_id for flag-linked plans (the person the plan is about).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_engagement_action_plans')) {
            return;
        }

        // Drop the survey FK first so the column can be made nullable.
        Schema::table('hr_engagement_action_plans', function (Blueprint $table) {
            $table->dropForeign(['survey_id']);
        });

        Schema::table('hr_engagement_action_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('survey_id')->nullable()->change();
            $table->foreign('survey_id')->references('id')->on('hr_engagement_surveys')->nullOnDelete();

            if (! Schema::hasColumn('hr_engagement_action_plans', 'source_type')) {
                $table->string('source_type')->nullable()->after('survey_id'); // survey | flag | manual
            }
            if (! Schema::hasColumn('hr_engagement_action_plans', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
            if (! Schema::hasColumn('hr_engagement_action_plans', 'staff_user_id')) {
                $table->foreignId('staff_user_id')->nullable()->after('source_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_engagement_action_plans')) {
            return;
        }

        Schema::table('hr_engagement_action_plans', function (Blueprint $table) {
            if (Schema::hasColumn('hr_engagement_action_plans', 'staff_user_id')) {
                $table->dropForeign(['staff_user_id']);
                $table->dropColumn('staff_user_id');
            }
            if (Schema::hasColumn('hr_engagement_action_plans', 'source_id')) {
                $table->dropColumn('source_id');
            }
            if (Schema::hasColumn('hr_engagement_action_plans', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
