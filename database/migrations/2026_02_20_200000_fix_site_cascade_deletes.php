<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Fix site_checklist_assignments.template_id — cascade on delete
        Schema::table('site_checklist_assignments', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->foreign('template_id')
                ->references('id')
                ->on('site_checklist_templates')
                ->cascadeOnDelete();
        });

        // Fix site_checklist_runs.assignment_id — cascade on delete
        Schema::table('site_checklist_runs', function (Blueprint $table) {
            $table->dropForeign(['assignment_id']);
            $table->foreign('assignment_id')
                ->references('id')
                ->on('site_checklist_assignments')
                ->cascadeOnDelete();
        });

        // Fix site_checklist_responses.template_item_id — cascade on delete
        Schema::table('site_checklist_responses', function (Blueprint $table) {
            $table->dropForeign(['template_item_id']);
            $table->foreign('template_item_id')
                ->references('id')
                ->on('site_checklist_template_items')
                ->cascadeOnDelete();
        });

        // Fix site_inspection_records.schedule_id — cascade on delete
        Schema::table('site_inspection_records', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
            $table->foreign('schedule_id')
                ->references('id')
                ->on('site_inspection_schedules')
                ->cascadeOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('site_checklist_assignments', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->foreign('template_id')
                ->references('id')
                ->on('site_checklist_templates');
        });

        Schema::table('site_checklist_runs', function (Blueprint $table) {
            $table->dropForeign(['assignment_id']);
            $table->foreign('assignment_id')
                ->references('id')
                ->on('site_checklist_assignments');
        });

        Schema::table('site_checklist_responses', function (Blueprint $table) {
            $table->dropForeign(['template_item_id']);
            $table->foreign('template_item_id')
                ->references('id')
                ->on('site_checklist_template_items');
        });

        Schema::table('site_inspection_records', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
            $table->foreign('schedule_id')
                ->references('id')
                ->on('site_inspection_schedules');
        });
    }
};
