<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MaintenanceRollbackGuard
{
    /** Check the complete package before the first destructive down operation. */
    public static function assertEmpty(): void
    {
        foreach (['fleet_maintenance_reports', 'fleet_maintenance_actions', 'fleet_maintenance_restrictions',
            'fleet_maintenance_attachments', 'fleet_maintenance_effects', 'fleet_maintenance_fin_bill_links',
            'fleet_maintenance_reviewer_grants', 'fleet_maintenance_booking_impacts',
            'fleet_maintenance_check_assessments',
            'fleet_maintenance_configuration_events', 'fleet_maintenance_site_route_history',
            'fleet_maintenance_site_routes', 'fleet_maintenance_policy_assignments',
            'fleet_maintenance_policy_versions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('PKG-01 evidence or approved configuration exists; rollback must not remove its provenance.');
            }
        }
        if (Schema::hasColumn('fleet_checklist_runs', 'presented_template_json')) {
            $columns = ['presented_template_json', 'rule_snapshot_json', 'rule_sha256', 'outcome',
                'submitted_at', 'request_key', 'request_fingerprint', 'covered_restriction_ids_json',
                'corrects_run_id', 'rule_version_id', 'source_report_id', 'work_order_id', 'observed_at'];
            if (DB::table('fleet_checklist_runs')->where(function ($query) use ($columns): void {
                foreach ($columns as $column) $query->orWhereNotNull($column);
            })->exists()) {
                throw new RuntimeException('PKG-01 check evidence exists, including standalone checks; preserve the original source.');
            }
        }
    }
}
