<?php

namespace App\Services\Compliance;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\AuditLog;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientSupportPlan;
use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for the Compliance command centre (/compliance) and any
 * governance dashboard card that mirrors its numbers. Lifts the KPI/trend queries
 * that previously lived inline in ComplianceDashboardController and adds the
 * "what's due" assurance register + a period-aware window (14d / 30d / 90d).
 *
 * Org-scoping is applied only where the underlying table actually carries
 * organization_id, so the dashboard is safe in single- and multi-tenant installs.
 */
class ComplianceMetricsService
{
    private const PERIODS = ['14d' => 14, '30d' => 30, '90d' => 90];

    public function normalisePeriod(?string $period): string
    {
        return array_key_exists((string) $period, self::PERIODS) ? (string) $period : '30d';
    }

    public function days(string $period): int
    {
        return self::PERIODS[$period] ?? 30;
    }

    private function scopeOrg(Builder $query, string $table, ?int $orgId): Builder
    {
        if ($orgId && Schema::hasColumn($table, 'organization_id')) {
            $query->where('organization_id', $orgId);
        }

        return $query;
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
    public function exceptionKpis(?int $orgId, string $period): array
    {
        $days = $this->days($period);
        $today = Carbon::today();
        $from = Carbon::now()->subDays($days);

        $incidents = $this->scopeOrg(ClientIncident::query(), 'client_incidents', $orgId);
        $openIncidents = (clone $incidents)->whereIn('status', ['submitted', 'reviewed'])->count();

        $cd = $this->scopeOrg(ClientControlledDrugDiscrepancy::query(), 'client_controlled_drug_discrepancies', $orgId);
        $openCd = (clone $cd)->where('status', 'open')->count();

        $mar = $this->scopeOrg(ClientMedicationAdministration::query(), 'client_medication_administrations', $orgId);
        $marExceptions = (clone $mar)
            ->whereDate('scheduled_for', $today)
            ->whereIn('status', ['missed', 'refused', 'withheld'])
            ->count();
        $marExceptionSeries = $this->scopeOrg(ClientMedicationAdministration::query(), 'client_medication_administrations', $orgId)
            ->whereIn('status', ['missed', 'refused', 'withheld']);

        $bg = $this->scopeOrg(ClientBreakGlassAccess::query(), 'client_break_glass_accesses', $orgId);
        $breakGlass = (clone $bg)->where('created_at', '>=', $from)->count();

        $overdueObligations = ComplianceObligation::overdue()->count();

        $audit = $this->scopeOrg(AuditLog::query(), 'audit_logs', $orgId);
        $auditEvents = (clone $audit)->where('created_at', '>=', $from)->count();

        return [
            [
                'key' => 'incidents', 'label' => 'Open incidents', 'value' => $openIncidents,
                'caption' => 'Submitted / reviewed', 'href' => '/incidents?tab=open',
                'tone' => $openIncidents > 0 ? 'warning' : 'success',
                'spark' => $this->dailyCounts(clone $incidents, 'created_at', $days),
            ],
            [
                'key' => 'cd', 'label' => 'CD discrepancies', 'value' => $openCd,
                'caption' => 'Open controlled-drug', 'href' => '/medications?tab=controlled',
                'tone' => $openCd > 0 ? 'critical' : 'success',
                'spark' => $this->dailyCounts(clone $cd, 'created_at', $days),
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
                'caption' => "Logged activity · {$period}", 'href' => '/audit-logs',
                'tone' => 'info',
                'spark' => $this->dailyCounts(clone $audit, 'created_at', $days),
            ],
        ];
    }

    /**
     * The "what's due / assurance" register: obligations due-soon/overdue + care-plan
     * reviews due. (Recurring registers have no single source today — deferred.)
     */
    public function whatsDue(?int $orgId): array
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

        $reviews = ClientSupportPlan::query()
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

    public function controlRoomSummary(): array
    {
        $unresolved = fn () => ControlRoomAlert::query()->whereNotIn('status', ['resolved', 'closed']);

        $recentAlerts = $unresolved()
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
            ->all();

        $alertTrend = ControlRoomAlert::query()
            ->where('triggered_at', '>=', Carbon::now()->subDays(14))
            ->selectRaw('DATE(triggered_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->d, 'total' => (int) $r->total])
            ->values()
            ->all();

        return [
            'open' => $unresolved()->count(),
            'critical' => $unresolved()->where('severity', 'critical')->count(),
            'escalated' => $unresolved()->where('escalation_level', '>', 0)->count(),
            'recentAlerts' => $recentAlerts,
            'alertTrend' => $alertTrend,
        ];
    }

    public function trends(?int $orgId, string $period): array
    {
        $days = $this->days($period);
        $from = Carbon::now()->subDays($days);
        $fromMar = Carbon::now()->subDays(min($days, 30));

        $incidentBySeverity = $this->scopeOrg(ClientIncident::query(), 'client_incidents', $orgId)
            ->where('created_at', '>=', $from)
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->get()
            ->map(fn ($r) => ['severity' => (string) $r->severity, 'total' => (int) $r->total])
            ->values()
            ->all();

        $marTrend = $this->scopeOrg(ClientMedicationAdministration::query(), 'client_medication_administrations', $orgId)
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

        $cdTrend = $this->scopeOrg(ClientControlledDrugDiscrepancy::query(), 'client_controlled_drug_discrepancies', $orgId)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->d, 'total' => (int) $r->total])
            ->values()
            ->all();

        return [
            'incidentBySeverity' => $incidentBySeverity,
            'marTrend' => $marTrend,
            'cdTrend' => $cdTrend,
        ];
    }
}
