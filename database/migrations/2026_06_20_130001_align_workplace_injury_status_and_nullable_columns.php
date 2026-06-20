<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Injuries & RTW redesign — Step 1. Reconcile the workplace-injury data layer with
 * the canonical lifecycle (reported → under_treatment → return_to_work → recovered →
 * closed). The original migration defaulted workplace_injuries.status to 'active',
 * which the controller never writes (store() hardcodes 'reported') and which the dead
 * scopeActive() filtered on — so the default and any stray 'active' rows are corrected
 * to 'reported'. Also makes two columns nullable to match the controller's validation
 * (modified_duties.restrictions + work_capacity_assessments.assessor_name were NOT NULL
 * in the schema but validated as nullable, so a null value passed validation then failed
 * the DB insert).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workplace_injuries')) {
            // Default 'active' → 'reported' (canonical first stage).
            Schema::table('workplace_injuries', function (Blueprint $table) {
                $table->string('status')->default('reported')->change();
            });

            // Backfill any rows still on the dead 'active' status so they appear in the
            // status-filtered register tabs.
            DB::table('workplace_injuries')->where('status', 'active')->update(['status' => 'reported']);
        }

        if (Schema::hasTable('modified_duties') && Schema::hasColumn('modified_duties', 'restrictions')) {
            Schema::table('modified_duties', function (Blueprint $table) {
                $table->text('restrictions')->nullable()->change();
            });
        }

        if (Schema::hasTable('work_capacity_assessments') && Schema::hasColumn('work_capacity_assessments', 'assessor_name')) {
            Schema::table('work_capacity_assessments', function (Blueprint $table) {
                $table->string('assessor_name')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workplace_injuries')) {
            Schema::table('workplace_injuries', function (Blueprint $table) {
                $table->string('status')->default('active')->change();
            });
        }

        if (Schema::hasTable('modified_duties') && Schema::hasColumn('modified_duties', 'restrictions')) {
            Schema::table('modified_duties', function (Blueprint $table) {
                $table->text('restrictions')->nullable(false)->change();
            });
        }

        if (Schema::hasTable('work_capacity_assessments') && Schema::hasColumn('work_capacity_assessments', 'assessor_name')) {
            Schema::table('work_capacity_assessments', function (Blueprint $table) {
                $table->string('assessor_name')->nullable(false)->change();
            });
        }
    }
};
