<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B vehicle checks & inspections: controlled checklist versions, the
 * vehicle's check requirement, attributable amendments to a submitted check,
 * and the exact checklist version each new check was answered against.
 *
 * A checklist (fleet_checklist_templates, shared with Maintenance checklists)
 * keeps its current content on the template row, which is what PKG-01 check
 * rules approve and snapshot. Every published change adds an immutable
 * version row; earlier versions and submitted checks are never rewritten.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_checklist_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('fleet_checklist_templates', 'id', 'fleet_ctv_template_fk')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 255);
            // When the checklist is used ("Before vehicle use", ...).
            $table->string('use_label', 120);
            // all_vehicles | accessible_vehicles | vehicle
            $table->string('assignment', 32);
            $table->foreignId('assignment_asset_id')->nullable()
                ->constrained('assets', 'id', 'fleet_ctv_asset_fk')->restrictOnDelete();
            $table->boolean('evidence_required')->default(false);
            $table->json('items');
            // MaintenanceFingerprint of the items: what an approved check rule pins.
            $table->char('items_sha256', 64);
            // library: published from the vehicle checklist library.
            // existing_template: captured from a Maintenance checklist when it was
            // first used or changed here, so its original content stays on record.
            $table->string('source', 32);
            $table->foreignId('published_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_ctv_publisher_fk')->restrictOnDelete();
            $table->dateTime('published_at');
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['template_id', 'version'], 'fleet_ctv_template_version_uq');
            $table->unique(['published_by_user_id', 'request_key'], 'fleet_ctv_request_uq');
            $table->index(['assignment', 'assignment_asset_id'], 'fleet_ctv_assignment_idx');
        });

        // The check a vehicle is expected to have: which checklist and who owns
        // it. The due date stays on assets.inspection_due_at, which the vehicle
        // header, readiness and asset reports already read.
        Schema::create('fleet_vehicle_check_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets', 'id', 'fleet_vcr_asset_fk')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()
                ->constrained('fleet_checklist_templates', 'id', 'fleet_vcr_template_fk')->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_vcr_owner_fk')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_vcr_updater_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique('asset_id', 'fleet_vcr_asset_uq');
        });

        // A correction note kept beside a submitted check. The check's own
        // answers, files and outcome never change.
        Schema::create('fleet_checklist_run_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('fleet_checklist_runs', 'id', 'fleet_cra_run_fk')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets', 'id', 'fleet_cra_asset_fk')->restrictOnDelete();
            $table->text('note');
            $table->foreignId('recorded_by_user_id')->constrained('users', 'id', 'fleet_cra_actor_fk')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['recorded_by_user_id', 'request_key'], 'fleet_cra_request_uq');
            $table->index(['run_id', 'recorded_at'], 'fleet_cra_run_idx');
        });

        Schema::table('fleet_checklist_runs', function (Blueprint $table): void {
            $table->foreignId('template_version_id')->nullable()->after('template_id')
                ->constrained('fleet_checklist_template_versions', 'id', 'fleet_cr_template_version_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $hasRecords = DB::table('fleet_checklist_template_versions')->exists()
            || DB::table('fleet_vehicle_check_requirements')->exists()
            || DB::table('fleet_checklist_run_amendments')->exists()
            || DB::table('fleet_checklist_runs')->whereNotNull('template_version_id')->exists();
        if ($hasRecords) {
            throw new RuntimeException('PKG-02B vehicle check records exist; preserve them instead of rolling back vehicle checks.');
        }

        Schema::table('fleet_checklist_runs', function (Blueprint $table): void {
            $table->dropForeign('fleet_cr_template_version_fk');
            $table->dropColumn('template_version_id');
        });
        Schema::dropIfExists('fleet_checklist_run_amendments');
        Schema::dropIfExists('fleet_vehicle_check_requirements');
        Schema::dropIfExists('fleet_checklist_template_versions');
    }
};
