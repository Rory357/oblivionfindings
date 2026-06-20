<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gold-standard Hazards rebuild — capture-completeness columns.
 *
 * `site_hazards` already carries the rich risk/control/residual/closure set
 * (see 2026_02_08 + 2026_03_28 migrations). This adds the few surfaces the
 * redesigned register exposes but the schema lacked: a free-text location and
 * witnesses on the hazard, and a distinct supporting-documents store alongside
 * the existing image-only `photo_paths`.
 *
 * `site_hazard_actions` gains the gold-standard corrective-action fields the
 * Events corrective-action register already has (reference, type, due date),
 * and the `tenant_id` column the model already declares fillable but the
 * original migration never created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_hazards', function (Blueprint $table) {
            if (! Schema::hasColumn('site_hazards', 'location')) {
                $table->string('location')->nullable()->after('description');
            }
            if (! Schema::hasColumn('site_hazards', 'witnesses')) {
                $table->text('witnesses')->nullable()->after('location');
            }
            if (! Schema::hasColumn('site_hazards', 'document_paths')) {
                $table->json('document_paths')->nullable()->after('photo_paths');
            }
        });

        Schema::table('site_hazard_actions', function (Blueprint $table) {
            if (! Schema::hasColumn('site_hazard_actions', 'tenant_id')) {
                $table->foreignId('tenant_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('site_hazard_actions', 'reference_number')) {
                $table->string('reference_number', 50)->nullable()->after('hazard_id')->index();
            }
            if (! Schema::hasColumn('site_hazard_actions', 'action_type')) {
                $table->string('action_type', 50)->nullable()->after('action_description');
            }
            if (! Schema::hasColumn('site_hazard_actions', 'due_date')) {
                $table->date('due_date')->nullable()->after('assigned_to_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_hazards', function (Blueprint $table) {
            foreach (['location', 'witnesses', 'document_paths'] as $col) {
                if (Schema::hasColumn('site_hazards', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('site_hazard_actions', function (Blueprint $table) {
            if (Schema::hasColumn('site_hazard_actions', 'tenant_id')) {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
            foreach (['reference_number', 'action_type', 'due_date'] as $col) {
                if (Schema::hasColumn('site_hazard_actions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
