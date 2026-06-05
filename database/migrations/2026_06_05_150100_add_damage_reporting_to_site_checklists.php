<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folds the legacy house-checklists "damage report" capability into the unified
 * run flow: a flagged item that fails can now spawn a SiteDamage (mirroring the
 * existing failure_creates_hazard → SiteHazard path). created_damage_id makes
 * the follow-up idempotent so re-saving a run never duplicates the record.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_checklist_template_items', function (Blueprint $table) {
            $table->boolean('failure_creates_damage')
                ->default(false)
                ->after('failure_creates_hazard');
        });

        Schema::table('site_checklist_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('created_damage_id')
                ->nullable()
                ->after('created_hazard_id');
        });
    }

    public function down(): void
    {
        Schema::table('site_checklist_template_items', function (Blueprint $table) {
            $table->dropColumn('failure_creates_damage');
        });

        Schema::table('site_checklist_responses', function (Blueprint $table) {
            $table->dropColumn('created_damage_id');
        });
    }
};
