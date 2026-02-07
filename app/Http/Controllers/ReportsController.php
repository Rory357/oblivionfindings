<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CombinedReportController;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Support\ReportCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $from7 = now()->subDays(7)->startOfDay();
        $today = now()->startOfDay();

        $kpis = [
            'openIncidents' => ClientIncident::query()
                ->whereIn('status', ['submitted', 'reviewed'])
                ->count(),
            'missedMeds7d' => ClientMedicationAdministration::query()
                ->where('created_at', '>=', $from7)
                ->whereIn('status', ['missed', 'withheld', 'refused'])
                ->count(),
            'completedShifts7d' => Shift::query()
                ->where('starts_at', '>=', $from7)
                ->where('status', 'completed')
                ->count(),
            'openSafeguarding' => SafeguardingConcern::query()
                ->where('status', '!=', 'closed')
                ->count(),
            'openDiscrepancies' => ClientControlledDrugDiscrepancy::query()
                ->where('status', 'open')
                ->count(),
            'overdueAssetChecks' => Asset::query()
                ->where(function ($q) use ($today) {
                    $q->where(function ($x) use ($today) {
                        $x->where('requires_inspection', true)
                            ->whereNotNull('inspection_due_at')
                            ->where('inspection_due_at', '<', $today->toDateString());
                    })->orWhere(function ($x) use ($today) {
                        $x->where('requires_maintenance', true)
                            ->whereNotNull('maintenance_due_at')
                            ->where('maintenance_due_at', '<', $today->toDateString());
                    });
                })
                ->count(),
            'auditEvents7d' => AuditLog::query()
                ->where('created_at', '>=', $from7)
                ->count(),
        ];

        $moduleSummaries = collect(ReportCatalog::modules())
            ->map(function (array $module): array {
                $modelClass = $module['model'];
                $model = new $modelClass();
                $table = $model->getTable();
                $dateField = (string) ($module['date_field'] ?? 'created_at');

                $hasDateField = Schema::hasColumn($table, $dateField);
                $hasStatus = Schema::hasColumn($table, 'status');

                $lastActivity = null;
                if ($hasDateField) {
                    $last = $modelClass::query()->max($dateField);
                    $lastActivity = $last ? (string) $last : null;
                } elseif (Schema::hasColumn($table, 'created_at')) {
                    $last = $modelClass::query()->max('created_at');
                    $lastActivity = $last ? (string) $last : null;
                }

                return array_merge($module, [
                    'summary' => [
                        'total_records' => $modelClass::query()->count(),
                        'last_activity' => $lastActivity,
                        'has_status_filter' => $hasStatus,
                        'has_search_filter' => !empty($module['search_columns']),
                        'has_date_filter' => $hasDateField,
                    ],
                ]);
            })
            ->values();

        $combinedReports = collect(CombinedReportController::definitions())
            ->map(function (array $definition) use ($kpis): array {
                $preview = [];
                if ($definition['key'] === 'care-quality') {
                    $preview = [
                        ['label' => 'Open incidents', 'value' => $kpis['openIncidents']],
                        ['label' => 'Medication exceptions (7d)', 'value' => $kpis['missedMeds7d']],
                        ['label' => 'Open safeguarding', 'value' => $kpis['openSafeguarding']],
                    ];
                } elseif ($definition['key'] === 'workforce-operations') {
                    $preview = [
                        ['label' => 'Completed shifts (7d)', 'value' => $kpis['completedShifts7d']],
                        ['label' => 'Audit events (7d)', 'value' => $kpis['auditEvents7d']],
                    ];
                } elseif ($definition['key'] === 'compliance-risk') {
                    $preview = [
                        ['label' => 'Overdue asset checks', 'value' => $kpis['overdueAssetChecks']],
                        ['label' => 'Open discrepancies', 'value' => $kpis['openDiscrepancies']],
                        ['label' => 'Audit events (7d)', 'value' => $kpis['auditEvents7d']],
                    ];
                }

                return array_merge($definition, ['preview' => $preview]);
            })
            ->values();

        return inertia('reports/index', [
            'kpis' => $kpis,
            'modules' => $moduleSummaries,
            'combined_reports' => $combinedReports,
        ]);
    }
}
