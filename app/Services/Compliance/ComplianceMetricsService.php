<?php

namespace App\Services\Compliance;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientSupportPlan;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for the Compliance command centre (/compliance) and any
 * governance dashboard card that mirrors its numbers. Lifts the KPI/trend queries
 * that previously lived inline in ComplianceDashboardController and adds the
 * "what's due" assurance register + a period-aware window (14d / 30d / 90d).
 *
 * Legacy compatibility columns are never used as application authorization.
 * Each domain query must use its canonical ownership boundary.
 */
class ComplianceMetricsService
{
    private const PERIODS = ['14d' => 14, '30d' => 30, '90d' => 90];

    private const INCIDENT_SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites', 'reports.viewAny'];

    private const CLIENT_SITE_BYPASS_PERMISSIONS = ['clients.viewAny', 'reports.viewAny'];

    private const CONTROL_ROOM_SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationGovernanceScopeService $medicationScope,
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {}

    public function normalisePeriod(?string $period): string
    {
        return array_key_exists((string) $period, self::PERIODS) ? (string) $period : '30d';
    }

    public function days(string $period): int
    {
        return self::PERIODS[$period] ?? 30;
    }

    /**
     * A continuous daily count series of length `$days` (zero-filled) for sparklines.
     */
    private function dailyCounts(Builder $query, string $dateColumn, int $days): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $rows = (clone $query)
            ->where($dateColumn, '>=', $from)
            ->selectRaw("DATE($dateColumn) as d, COUNT(*) as total")
            ->groupBy('d')
            ->pluck('total', 'd');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $series[] = (int) ($rows[$d] ?? 0);
        }

        return $series;
    }

    /**
     * The six exception KPIs (value + sparkline + status tone + drill href).
     */
    public function exceptionKpis(User $viewer, string $period): array
    {
        $days = $this->days($period);
        $today = Carbon::today();
        $from = Carbon::now()->subDays($days);
        $medicationSiteIds = $this->medicationSiteIds($viewer);
        $canViewControlled = $viewer->canDo(
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        );

        $incidents = ClientIncident::query();
        $this->siteAccess->applyClientIncidentScope($incidents, $viewer, self::INCIDENT_SITE_BYPASS_PERMISSIONS);
        $openIncidents = (clone $incidents)->whereIn('status', ['submitted', 'reviewed'])->count();

        $mar = $this->medicationAdministrationQuery($viewer, $medicationSiteIds);
        $marExceptions = (clone $mar)
            ->whereDate('scheduled_for', $today)
            ->whereIn('status', ['missed', 'refused', 'withheld'])
            ->count();
        $marExceptionSeries = (clone $mar)->whereIn('status', ['missed', 'refused', 'withheld']);

        $bg = $this->scopeClientOwnedForSiteIds(
            ClientBreakGlassAccess::query(),
            $medicationSiteIds,
        );
        $breakGlass = (clone $bg)->where('created_at', '>=', $from)->count();

        $overdueObligations = ComplianceObligation::overdue()->count();

        $audit = $this->visibleAuditActivity($viewer);
        $auditEvents = (clone $audit)->where('created_at', '>=', $from)->count();

        $kpis = [
            [
                'key' => 'incidents', 'label' => 'Open incidents', 'value' => $openIncidents,
                'caption' => 'Submitted / reviewed', 'href' => '/incidents?tab=open',
                'tone' => $openIncidents > 0 ? 'warning' : 'success',
                'spark' => $this->dailyCounts(clone $incidents, 'created_at', $days),
            ],
            [
                'key' => 'mar', 'label' => 'MAR exceptions', 'value' => $marExceptions,
                'caption' => 'Missed / refused / withheld today', 'href' => '/medications?tab=mar',
                'tone' => $marExceptions > 0 ? 'warning' : 'success',
                'spark' => $this->dailyCounts($marExceptionSeries, 'scheduled_for', $days),
            ],
            [
                'key' => 'break_glass', 'label' => 'Break-glass', 'value' => $breakGlass,
                'caption' => "Emergency access · {$period}", 'href' => '/emar/emergency-access',
                'tone' => $breakGlass > 0 ? 'warning' : 'success',
                'spark' => $this->dailyCounts(clone $bg, 'created_at', $days),
            ],
            [
                'key' => 'obligations', 'label' => 'Overdue obligations', 'value' => $overdueObligations,
                'caption' => 'Governance register', 'href' => '/governance/compliance?status=overdue',
                'tone' => $overdueObligations > 0 ? 'critical' : 'success',
                'spark' => [],
            ],
            [
                'key' => 'audit', 'label' => 'Audit events', 'value' => $auditEvents,
                'caption' => "Visible compliance activity · {$period}",
                'href' => $viewer->canDo('audit.viewAny') ? '/audit-logs' : null,
                'tone' => 'info',
                'spark' => $this->dailyCounts(clone $audit, 'created_at', $days),
            ],
        ];

        if ($canViewControlled) {
            $cd = $this->controlledDiscrepancyQuery($viewer, $medicationSiteIds);
            $openCd = (clone $cd)->where('status', 'open')->count();
            array_splice($kpis, 1, 0, [[
                'key' => 'cd', 'label' => 'CD discrepancies', 'value' => $openCd,
                'caption' => 'Open controlled-drug', 'href' => '/medications?tab=controlled',
                'tone' => $openCd > 0 ? 'critical' : 'success',
                'spark' => $this->dailyCounts(clone $cd, 'created_at', $days),
            ]]);
        }

        return $kpis;
    }

    /**
     * The "what's due / assurance" register: obligations due-soon/overdue + care-plan
     * reviews due. (Recurring registers have no single source today — deferred.)
     */
    public function whatsDue(User $viewer): array
    {
        $obligations = ComplianceObligation::query()
            ->dueSoon(30)
            ->with('owner')
            ->orderBy('due_date')
            ->limit(30)
            ->get()
            ->map(fn (ComplianceObligation $o) => [
                'id' => $o->id,
                'type' => 'obligation',
                'title' => $o->obligation_title,
                'framework' => $o->getFrameworkLabel(),
                'reference' => $o->obligation_code,
                'priority' => $o->priority ?? 'medium',
                'due_date' => $o->due_date?->toDateString(),
                'days' => $o->due_date ? (int) round(Carbon::now()->diffInDays($o->due_date, false)) : null,
                'owner' => $o->owner?->name,
                'status' => $o->status,
                'evidence_provided' => (bool) $o->evidence_provided,
                'href' => "/governance/compliance/{$o->id}",
            ])
            ->values()
            ->all();

        $reviews = $this->scopeClientOwned(
            ClientSupportPlan::query(),
            $viewer,
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
        )
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', Carbon::now()->addDays(30))
            ->with('client')
            ->orderBy('next_review_at')
            ->limit(30)
            ->get()
            ->map(function (ClientSupportPlan $plan) {
                $name = $plan->client
                    ? trim(($plan->client->first_name ?? '').' '.($plan->client->last_name ?? ''))
                    : null;
                $overdue = $plan->next_review_at && $plan->next_review_at->isPast();

                return [
                    'id' => $plan->id,
                    'type' => 'review',
                    'title' => $name !== '' && $name !== null ? $name : "Client #{$plan->client_id}",
                    'framework' => 'Care & support plan review',
                    'reference' => null,
                    'priority' => $overdue ? 'high' : 'medium',
                    'due_date' => $plan->next_review_at?->toDateString(),
                    'days' => $plan->next_review_at ? (int) round(Carbon::now()->diffInDays($plan->next_review_at, false)) : null,
                    'owner' => null,
                    'status' => $overdue ? 'overdue' : 'due_soon',
                    'evidence_provided' => false,
                    'client_id' => $plan->client_id,
                    'href' => "/operations/clients/{$plan->client_id}?tab=care_plans",
                ];
            })
            ->values()
            ->all();

        return ['obligations' => $obligations, 'reviews' => $reviews];
    }

    public function controlRoomSummary(User $viewer): array
    {
        $actionableAlerts = function () use ($viewer): Builder {
            $query = ControlRoomAlert::query()->actionable();
            $this->siteAccess->applyAlertScope($query, $viewer, self::CONTROL_ROOM_SITE_BYPASS_PERMISSIONS);
            $this->alertAccess->applyControlledMedicationContentScope($query, $viewer);

            return $query;
        };

        $recentAlerts = $viewer->canDo('controlRoom.viewAny')
            ? $actionableAlerts()
                ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 WHEN severity = 'high' THEN 1 WHEN severity = 'medium' THEN 2 ELSE 3 END")
                ->orderByDesc('triggered_at')
                ->limit(6)
                ->get()
                ->map(fn (ControlRoomAlert $a) => [
                    'id' => $a->id,
                    'alert_type' => $a->alert_type,
                    'severity' => $a->severity,
                    'status' => $a->status,
                    'source' => $a->source,
                    'triggered_at' => $a->triggered_at?->toISOString(),
                ])
                ->values()
                ->all()
            : [];

        $alertTrendQuery = ControlRoomAlert::query();
        $this->siteAccess->applyAlertScope(
            $alertTrendQuery,
            $viewer,
            self::CONTROL_ROOM_SITE_BYPASS_PERMISSIONS,
        );
        $this->alertAccess->applyControlledMedicationContentScope($alertTrendQuery, $viewer);
        $alertTrend = $alertTrendQuery
            ->where('triggered_at', '>=', Carbon::now()->subDays(14))
            ->selectRaw('DATE(triggered_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->d, 'total' => (int) $r->total])
            ->values()
            ->all();

        return [
            'open' => $actionableAlerts()->count(),
            'critical' => $actionableAlerts()->where('severity', 'critical')->count(),
            'escalated' => $actionableAlerts()->where('escalation_level', '>', 0)->count(),
            'recentAlerts' => $recentAlerts,
            'alertTrend' => $alertTrend,
        ];
    }

    public function trends(User $viewer, string $period): array
    {
        $days = $this->days($period);
        $from = Carbon::now()->subDays($days);
        $fromMar = Carbon::now()->subDays(min($days, 30));
        $medicationSiteIds = $this->medicationSiteIds($viewer);

        $incidents = ClientIncident::query();
        $this->siteAccess->applyClientIncidentScope($incidents, $viewer, self::INCIDENT_SITE_BYPASS_PERMISSIONS);
        $incidentBySeverity = $incidents
            ->where('created_at', '>=', $from)
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->get()
            ->map(fn ($r) => ['severity' => (string) $r->severity, 'total' => (int) $r->total])
            ->values()
            ->all();

        $marTrend = $this->medicationAdministrationQuery($viewer, $medicationSiteIds)
            ->where('scheduled_for', '>=', $fromMar)
            ->selectRaw("DATE(scheduled_for) as d,
                SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given_total,
                SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed_total,
                SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused_total,
                SUM(CASE WHEN status = 'withheld' THEN 1 ELSE 0 END) as withheld_total")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->d,
                'given' => (int) $r->given_total,
                'missed' => (int) $r->missed_total,
                'refused' => (int) $r->refused_total,
                'withheld' => (int) $r->withheld_total,
            ])
            ->values()
            ->all();

        $cdTrend = [];
        if ($viewer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $cdTrend = $this->controlledDiscrepancyQuery($viewer, $medicationSiteIds)
                ->where('created_at', '>=', $from)
                ->selectRaw('DATE(created_at) as d, COUNT(*) as total')
                ->groupBy('d')
                ->orderBy('d')
                ->get()
                ->map(fn ($r) => ['date' => (string) $r->d, 'total' => (int) $r->total])
                ->values()
                ->all();
        }

        return [
            'incidentBySeverity' => $incidentBySeverity,
            'marTrend' => $marTrend,
            'cdTrend' => $cdTrend,
        ];
    }

    /** @param array<int, string> $bypassPermissions */
    private function scopeClientOwned(Builder $query, User $viewer, array $bypassPermissions): Builder
    {
        return $query->whereHas('client', fn (Builder $clients) => $this->siteAccess->applyClientScope(
            $clients,
            $viewer,
            $bypassPermissions,
        ));
    }

    /** @return array<int, int> */
    private function medicationSiteIds(User $viewer): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $viewer,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );
    }

    /** @param array<int, int> $siteIds */
    private function medicationAdministrationQuery(User $viewer, array $siteIds): Builder
    {
        $query = ClientMedicationAdministration::query()->effectiveClinicalEvidence();
        $this->medicationScope->scopeCanonicalClientMedicationRows(
            $query,
            $siteIds,
            allowNullMedication: false,
        );
        if (! $viewer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $this->medicationScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    /** @param array<int, int> $siteIds */
    private function controlledDiscrepancyQuery(User $viewer, array $siteIds): Builder
    {
        abort_unless(
            $viewer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            403,
        );
        $query = ClientControlledDrugDiscrepancy::query();
        $this->medicationScope->scopeCanonicalClientMedicationRows(
            $query,
            $siteIds,
            allowNullMedication: false,
        );

        return $query;
    }

    /** @param array<int, int> $siteIds */
    private function scopeClientOwnedForSiteIds(Builder $query, array $siteIds): Builder
    {
        return $query->whereHas('client', fn (Builder $clients): Builder => $clients
            ->whereIn('site_id', $siteIds));
    }

    /**
     * AuditLog is a mixed application ledger and has no universal Site column.
     * Restricted viewers see only audit rows whose canonical business record is
     * visible, plus the intentionally application-wide governance obligation log.
     */
    private function visibleAuditActivity(User $viewer): Builder
    {
        $audit = AuditLog::query();
        $visibleAlertIds = ControlRoomAlert::query()->select('control_room_alerts.id');
        $this->siteAccess->applyAlertScope(
            $visibleAlertIds,
            $viewer,
            self::CONTROL_ROOM_SITE_BYPASS_PERMISSIONS,
        );
        $this->alertAccess->applyControlledMedicationContentScope($visibleAlertIds, $viewer);
        $alertType = (new ControlRoomAlert)->getMorphClass();
        $audit->where(function (Builder $content) use ($alertType, $visibleAlertIds): void {
            $content->whereNull('auditable_type')
                ->orWhere('auditable_type', '!=', $alertType)
                ->orWhereIn('auditable_id', $visibleAlertIds);
        });

        // The mixed ledger cannot canonically classify every historical
        // medication action. Exclude medication families from this general
        // aggregate for every viewer; the medication audit owns that evidence.
        $audit->where(function (Builder $nonMedication): void {
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
        if ($viewer->canDo('reports.viewAny')) {
            return $audit;
        }

        $visibleClientIds = Client::query()->select('clients.id');
        $this->siteAccess->applyClientScope(
            $visibleClientIds,
            $viewer,
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
        );

        $visibleIncidentIds = ClientIncident::query()->select('client_incidents.id');
        $this->siteAccess->applyClientIncidentScope(
            $visibleIncidentIds,
            $viewer,
            self::INCIDENT_SITE_BYPASS_PERMISSIONS,
        );

        $incidentType = (new ClientIncident)->getMorphClass();
        $obligationType = (new ComplianceObligation)->getMorphClass();

        return $audit->where(function (Builder $visible) use (
            $visibleClientIds,
            $visibleIncidentIds,
            $visibleAlertIds,
            $incidentType,
            $alertType,
            $obligationType,
        ): void {
            $visible->whereIn('client_id', $visibleClientIds)
                ->orWhere(fn (Builder $incidents) => $incidents
                    ->where('auditable_type', $incidentType)
                    ->whereIn('auditable_id', $visibleIncidentIds))
                ->orWhere(fn (Builder $alerts) => $alerts
                    ->where('auditable_type', $alertType)
                    ->whereIn('auditable_id', $visibleAlertIds))
                ->orWhere('auditable_type', $obligationType);
        });
    }
}
