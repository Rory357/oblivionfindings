<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-01 Maintenance assessment of a submitted check: "No issue found —
 * release for use". One decision per check, never edited. The check's own
 * answers and outcome stay as submitted; MaintenanceRestrictionService stops
 * counting an assessed check as a vehicle blocker. Holds are untouched: they
 * are still released only by repair, retest and independent release.
 *
 * Foreign keys point only at tables the PKG-01 rollbacks keep (assets, users,
 * fleet_checklist_runs, fleet_work_orders), so those rollbacks can still run
 * on an empty package. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_maintenance_check_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets', 'id', 'fleet_mca_asset_fk')->restrictOnDelete();
            $table->foreignId('check_run_id')->constrained('fleet_checklist_runs', 'id', 'fleet_mca_run_fk')->restrictOnDelete();
            // The Maintenance work the check was recorded on or reported to, if any.
            $table->foreignId('work_order_id')->nullable()->constrained('fleet_work_orders', 'id', 'fleet_mca_work_fk')->restrictOnDelete();
            // no_issue_release: no issue found, released for use.
            $table->string('decision', 32);
            $table->text('reason');
            // The check as it was decided on (the run itself never changes).
            $table->string('run_outcome', 32);
            $table->unsignedBigInteger('run_rule_version_id')->nullable();
            // Recorded issue or unable-to-assess answers the assessor confirmed.
            $table->json('acknowledged_issues_json');
            // The vehicle readiness evidence the decision was made against.
            $table->json('readiness_json')->nullable();
            $table->foreignId('assessed_by_user_id')->constrained('users', 'id', 'fleet_mca_actor_fk')->restrictOnDelete();
            $table->dateTime('assessed_at', 6);
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique('check_run_id', 'fleet_mca_run_uq');
            $table->unique(['assessed_by_user_id', 'request_key'], 'fleet_mca_request_uq');
            $table->index(['asset_id', 'assessed_at'], 'fleet_mca_asset_time_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('fleet_maintenance_check_assessments')
            && DB::table('fleet_maintenance_check_assessments')->exists()) {
            throw new RuntimeException('PKG-01 check assessments exist; preserve these release decisions instead of rolling back.');
        }

        Schema::dropIfExists('fleet_maintenance_check_assessments');
    }
};
