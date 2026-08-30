<?php

namespace App\Http\Controllers;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Support\ReportCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ReportsController extends Controller
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $medicationScope,
        private readonly BoardPackAccessService $boardPackAccess,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $from7 = now()->subDays(7)->startOfDay();
        $today = now()->startOfDay();
        $medicationSiteIds = $this->medicationScope->reportSiteIds($user);
        $canViewControlled = $user->canDo(
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        );
        $administrations = $this->medicationAdministrationQuery(
            $user,
            $medicationSiteIds,
        );
        $generalAuditActivity = $this->generalAuditActivityQuery($user);

        $kpis = [
            'openIncidents' => ClientIncident::query()
                ->whereIn('status', ['submitted', 'reviewed'])
                ->count(),
            'missedMeds7d' => (clone $administrations)
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
            'auditEvents7d' => $generalAuditActivity
                ->where('created_at', '>=', $from7)
                ->count(),
        ];
        if ($canViewControlled) {
            $kpis['openDiscrepancies'] = $this->controlledDiscrepancyQuery(
                $user,
                $medicationSiteIds,
            )->where('status', 'open')->count();
        }

        $moduleSummaries = collect(ReportCatalog::modules())
            ->reject(fn (array $module): bool => $this->moduleMedicationGovernance($module) === 'controlled'
                && ! $canViewControlled)
            ->map(function (array $module) use ($user, $medicationSiteIds): array {
                $modelClass = $module['model'];
                $model = new $modelClass;
                $table = $model->getTable();
                $dateField = (string) ($module['date_field'] ?? 'created_at');
                $query = $this->moduleSummaryQuery($module, $user, $medicationSiteIds);

                $hasDateField = Schema::hasColumn($table, $dateField);
                $hasStatus = Schema::hasColumn($table, 'status');

                $lastActivity = null;
                if ($hasDateField) {
                    $last = (clone $query)->max($dateField);
                    $lastActivity = $last ? (string) $last : null;
                } elseif (Schema::hasColumn($table, 'created_at')) {
                    $last = (clone $query)->max('created_at');
                    $lastActivity = $last ? (string) $last : null;
                }

                return array_merge($module, [
                    'summary' => [
                        'total_records' => (clone $query)->count(),
                        'last_activity' => $lastActivity,
                        'has_status_filter' => $hasStatus,
                        'has_search_filter' => ! empty($module['search_columns']),
                        'has_date_filter' => $hasDateField,
                    ],
                ]);
            })
            ->values();

        $combinedReports = collect(CombinedReportController::definitions())
            ->map(function (array $definition) use ($kpis, $canViewControlled): array {
                if (! $canViewControlled) {
                    $definition['modules'] = collect($definition['modules'] ?? [])
                        ->reject(fn (string $module): bool => $module === 'controlled_drug_discrepancies')
                        ->values()
                        ->all();
                }

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
                        ['label' => 'Audit events (7d)', 'value' => $kpis['auditEvents7d']],
                    ];
                    if ($canViewControlled) {
                        array_splice($preview, 1, 0, [[
                            'label' => 'Open discrepancies',
                            'value' => $kpis['openDiscrepancies'],
                        ]]);
                    }
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

    /** @param array<int, int> $siteIds */
    private function medicationAdministrationQuery(User $user, array $siteIds): Builder
    {
        $query = ClientMedicationAdministration::query()->effectiveClinicalEvidence();
        $this->medicationScope->scopeCanonicalClientMedicationRows(
            $query,
            $siteIds,
            allowNullMedication: false,
        );
        if (! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $this->medicationScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    /** @param array<int, int> $siteIds */
    private function controlledDiscrepancyQuery(User $user, array $siteIds): Builder
    {
        $this->medicationScope->reportSiteIds($user, controlled: true);
        $query = ClientControlledDrugDiscrepancy::query();
        $this->medicationScope->scopeCanonicalClientMedicationRows(
            $query,
            $siteIds,
            allowNullMedication: false,
        );

        return $query;
    }

    private function generalAuditActivityQuery(User $user): Builder
    {
        $query = AuditLog::query();
        $this->excludeMedicationAuditFamilies($query);
        $this->boardPackAccess->scopeAuditVisibility($query, $user);

        return $query;
    }

    private function excludeMedicationAuditFamilies(Builder $query): void
    {
        $query->where(function (Builder $nonMedication): void {
            $nonMedication->whereNull('auditable_type')
                ->orWhere(function (Builder $typed): void {
                    $typed->where('auditable_type', 'not like', '%Medication%')
                        ->where('auditable_type', 'not like', '%ControlledDrug%');
                });
        })->where(function (Builder $nonMedicationAction): void {
            $nonMedicationAction->whereNull('action')
                ->orWhere(function (Builder $action): void {
                    $action->where('action', 'not like', 'medication%')
                        ->where('action', 'not like', 'meds.%')
                        ->where('action', 'not like', 'emar.%')
                        ->where('action', 'not like', 'clientmedication%')
                        ->where('action', 'not like', 'clientcontrolleddrug%')
                        ->where('action', 'not like', 'controlled_drug%')
                        ->where('action', 'not like', 'cd.%')
                        ->where('action', 'not like', 'cd\_%');
                });
        });
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<int, int>  $siteIds
     */
    private function moduleSummaryQuery(array $module, User $user, array $siteIds): Builder
    {
        return match ($this->moduleMedicationGovernance($module)) {
            'general_audit' => $this->generalAuditActivityQuery($user),
            'administration' => $this->medicationAdministrationQuery($user, $siteIds),
            'controlled' => $this->controlledDiscrepancyQuery($user, $siteIds),
            default => $module['model']::query(),
        };
    }

    /** @param array<string, mixed> $module */
    private function moduleMedicationGovernance(array $module): ?string
    {
        return match ($module['model'] ?? null) {
            AuditLog::class => 'general_audit',
            ClientMedicationAdministration::class => 'administration',
            ClientControlledDrugDiscrepancy::class => 'controlled',
            default => null,
        };
    }
}
