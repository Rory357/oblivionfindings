<?php

namespace App\Console\Commands;

use App\Models\FleetWorkOrder;
use App\Services\Fleet\MaintenanceRestrictionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Counts provenance gaps without assigning a current rule to historical facts. */
class ReconcileMaintenanceLegacy extends Command
{
    protected $signature = 'maintenance:reconcile-legacy {--json : Emit a machine-readable count report}';

    protected $description = 'Read-only maintenance legacy and Finance provenance counts';

    public function handle(): int
    {
        foreach (['fleet_maintenance_reports', 'fleet_maintenance_restrictions',
            'fleet_checklist_runs', 'fin_financial_events'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing {$table}; apply the reviewed additive migration first.");

                return self::FAILURE;
            }
        }

        $counts = [
            'work_orders_without_report' => DB::table('fleet_work_orders as work')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('fleet_maintenance_reports as report')
                    ->whereColumn('report.work_order_id', 'work.id'))->count(),
            // Daily checks are recorded observations that no rule evaluates, so they aren't gaps.
            'check_runs_without_exact_rule_snapshot' => DB::table('fleet_checklist_runs')
                ->where(fn ($kind) => $kind->whereNull('check_kind')
                    ->orWhereNotIn('check_kind', MaintenanceRestrictionService::OBSERVATION_KINDS))
                ->where(fn ($query) => $query->whereNull('rule_version_id')
                    ->orWhereNull('rule_snapshot_json')->orWhereNull('rule_sha256'))->count(),
            'out_of_service_assets_without_active_restriction' => DB::table('assets as asset')
                ->whereIn('asset.status', ['out_of_service', 'maintenance'])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('fleet_maintenance_restrictions as restriction')
                    ->whereColumn('restriction.asset_id', 'asset.id')
                    ->where('restriction.state', 'active'))->count(),
            'historical_work_order_financial_events' => DB::table('fin_financial_events')
                ->whereIn('source_type', [FleetWorkOrder::class, 'fleet_work_order'])
                ->count(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Provenance count', 'Rows'], collect($counts)
                ->map(fn (int $count, string $name) => [$name, $count])->values()->all());
            $this->line('Counts are read-only. Review individual source records before any data repair.');
        }

        return self::SUCCESS;
    }
}
