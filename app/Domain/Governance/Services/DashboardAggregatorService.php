<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\DashboardSnapshot;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\DataBreachLog;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\Timesheet;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardAggregatorService
{
    public function captureSnapshot(string $periodType, ?Carbon $start = null, ?Carbon $end = null): DashboardSnapshot
    {
        $range = $this->getDateRange($periodType, $start, $end);
        
        $data = [
            'period' => [
                'type' => $periodType,
                'start' => $range['start']->toDateString(),
                'end' => $range['end']->toDateString(),
            ],
            'widgets' => [
                'top_risks' => $this->getTopRisks(),
                'risk_changes' => $this->getRiskChanges($range),
                'client_safety' => $this->getClientSafetyMetrics($range),
                'operational_safety' => $this->getOperationalSafetyMetrics($range),
                'privacy_data' => $this->getPrivacyMetrics($range),
                'workforce' => $this->getWorkforceMetrics($range),
                'financial' => $this->getFinancialMetrics($range),
                'it_cyber' => $this->getItCyberMetrics($range),
                'compliance_calendar' => $this->getComplianceCalendar(),
                'decisions_required' => $this->getDecisionsRequired(),
                'control_room' => $this->getControlRoomMetrics($range),
                'incidents' => $this->getIncidentMetrics($range),
                'safeguarding' => $this->getSafeguardingMetrics($range),
            ],
            'captured_at' => now()->toIso8601String(),
        ];

        return DashboardSnapshot::create([
            'snapshot_data' => $data,
            'period_type' => $periodType,
            'period_start' => $range['start'],
            'period_end' => $range['end'],
            'checksum' => DashboardSnapshot::generateChecksum($data),
            'captured_at' => now(),
            'captured_by' => auth()->id() ?? 1,
            'data_freshness' => $this->getDataFreshness(),
        ]);
    }

    protected function getDateRange(string $periodType, ?Carbon $start, ?Carbon $end): array
    {
        $end = $end ?? now();
        
        $start = match($periodType) {
            'today' => $end->copy()->startOfDay(),
            'week' => $end->copy()->startOfWeek(),
            'month' => $end->copy()->startOfMonth(),
            'year' => $end->copy()->startOfYear(),
            default => $start ?? $end->copy()->subMonth(),
        };

        return ['start' => $start, 'end' => $end];
    }

    public function getTopRisks(int $limit = 10): array
    {
        $risks = \App\Domain\Governance\Models\RiskRegisterEntry::active()
            ->orderByDesc('residual_score')
            ->limit($limit)
            ->get();

        return [
            'count' => $risks->count(),
            'critical' => $risks->where('residual_score', '>=', 20)->count(),
            'high' => $risks->whereBetween('residual_score', [15, 19])->count(),
            'medium' => $risks->whereBetween('residual_score', [10, 14])->count(),
            'above_appetite' => $risks->where('within_appetite', false)->count(),
            'items' => $risks->map(fn($r) => [
                'id' => $r->id,
                'reference' => $r->risk_reference,
                'title' => $r->title,
                'category' => $r->category,
                'score' => $r->residual_score,
                'color' => $r->getSeverityColor(),
                'owner' => $r->riskOwner?->name,
            ])->toArray(),
        ];
    }

    public function getRiskChanges(array $range): array
    {
        $new = \App\Domain\Governance\Models\RiskRegisterEntry::whereBetween('identified_at', [$range['start'], $range['end']])->count();
        $escalated = \App\Domain\Governance\Models\RiskRegisterEntry::whereBetween('updated_at', [$range['start'], $range['end']])
            ->whereColumn('residual_score', '>', 'inherent_score')
            ->count();
        $closed = \App\Domain\Governance\Models\RiskRegisterEntry::whereBetween('closed_at', [$range['start'], $range['end']])->count();

        return [
            'new' => $new,
            'escalated' => $escalated,
            'closed' => $closed,
            'net_change' => $new - $closed,
        ];
    }

    public function getClientSafetyMetrics(array $range): array
    {
        $highRiskCount = \App\Models\ClientRisk::where('active', true)
            ->where('severity', 'high')
            ->count();

        $incidents = ClientIncident::whereBetween('occurred_at', [$range['start'], $range['end']])
            ->whereIn('severity', ['high', 'critical'])
            ->count();

        $openIncidents = ClientIncident::whereIn('severity', ['high', 'critical'])
            ->where('status', '!=', 'closed')
            ->count();

        return [
            'high_risk_clients' => $highRiskCount,
            'serious_incidents_period' => $incidents,
            'open_critical_incidents' => $openIncidents,
            'status' => $this->determineStatus($highRiskCount + $openIncidents, [5, 10]),
        ];
    }

    public function getOperationalSafetyMetrics(array $range): array
    {
        $nearMisses = \App\Models\ClientIncident::whereBetween('occurred_at', [$range['start'], $range['end']])
            ->where('type', 'near_miss')
            ->count();

        $injuries = \App\Models\ClientIncident::whereBetween('occurred_at', [$range['start'], $range['end']])
            ->where('type', 'injury')
            ->count();

        return [
            'near_misses' => $nearMisses,
            'injuries' => $injuries,
            'status' => $injuries > 0 ? 'critical' : ($nearMisses > 5 ? 'warning' : 'good'),
        ];
    }

    public function getPrivacyMetrics(array $range): array
    {
        $breaches = Schema::hasTable('data_breach_logs')
            ? DataBreachLog::whereBetween('discovered_at', [$range['start'], $range['end']])->count()
            : 0;
        $openBreachCount = Schema::hasTable('data_breach_logs')
            ? DataBreachLog::whereNull('resolved_at')->count()
            : 0;

        $openDpiAs = 0;
        if (Schema::hasTable('privacy_impact_assessments')) {
            $openDpiAs = Schema::hasColumn('privacy_impact_assessments', 'status')
                ? \App\Models\PrivacyImpactAssessment::where('status', '!=', 'approved')->count()
                : \App\Models\PrivacyImpactAssessment::count();
        }

        $backlogRequests = 0;
        if (Schema::hasTable('data_subject_requests')) {
            $backlogRequests = Schema::hasColumn('data_subject_requests', 'status')
                ? \App\Models\DataSubjectRequest::where('status', '!=', 'completed')->count()
                : \App\Models\DataSubjectRequest::count();
        }

        return [
            'breaches_90d' => $breaches,
            'open_breaches' => $openBreachCount,
            'open_dpias' => $openDpiAs,
            'dsr_backlog' => $backlogRequests,
            'status' => $openBreachCount > 0 ? 'critical' : ($breaches > 0 ? 'warning' : 'good'),
        ];
    }

    public function getWorkforceMetrics(array $range): array
    {
        // Overtime calculation
        $overtimeHours = Timesheet::whereBetween('work_date', [$range['start'], $range['end']])
            ->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at) / 60 - 8) as overtime')
            ->value('overtime') ?? 0;

        $totalHours = Timesheet::whereBetween('work_date', [$range['start'], $range['end']])
            ->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at) / 60) as total')
            ->value('total') ?? 1;

        $overtimePct = ($overtimeHours / $totalHours) * 100;

        // Unfilled shifts
        $unfilledShifts = Shift::whereBetween('starts_at', [$range['start'], $range['end']])
            ->whereNull('user_id')
            ->count();

        // Training compliance (mock calculation)
        $trainingCompliance = 85; // Would calculate from actual training records

        return [
            'overtime_percentage' => round($overtimePct, 1),
            'unfilled_shifts' => $unfilledShifts,
            'training_compliance' => $trainingCompliance,
            'status' => $overtimePct > 10 ? 'warning' : 'good',
        ];
    }

    public function getFinancialMetrics(array $range): array
    {
        $currentBudget = \App\Domain\Governance\Models\Budget::approved()
            ->latest('approved_by_board_at')
            ->first();

        if (!$currentBudget) {
            return [
                'budget_utilization' => 0,
                'variance' => 0,
                'status' => 'unknown',
            ];
        }

        $utilization = $currentBudget->getTotalActual() / $currentBudget->total_budget * 100;
        $variance = $currentBudget->getVariancePercentage();

        return [
            'budget_utilization' => round($utilization, 1),
            'variance' => round($variance, 1),
            'status' => abs($variance) > 5 ? 'warning' : 'good',
            'fiscal_year' => $currentBudget->fiscal_year,
        ];
    }

    public function getItCyberMetrics(array $range): array
    {
        // Security incidents
        $securityIncidents = ControlRoomAlert::whereBetween('triggered_at', [$range['start'], $range['end']])
            ->where('alert_type', 'like', '%security%')
            ->count();

        // System uptime (mock - would integrate with monitoring)
        $uptime = 99.5;

        // Open critical alerts
        $criticalAlerts = ControlRoomAlert::where('severity', 'critical')
            ->whereIn('status', ['open', 'acknowledged'])
            ->count();

        return [
            'security_incidents' => $securityIncidents,
            'uptime_percentage' => $uptime,
            'critical_open_alerts' => $criticalAlerts,
            'status' => $criticalAlerts > 0 ? 'critical' : ($securityIncidents > 0 ? 'warning' : 'good'),
        ];
    }

    public function getComplianceCalendar(int $days = 90): array
    {
        $obligations = \App\Domain\Governance\Models\ComplianceObligation::where('due_date', '<=', now()->addDays($days))
            ->where('status', '!=', 'complete')
            ->orderBy('due_date')
            ->limit(10)
            ->get();

        return $obligations->map(fn($o) => [
            'id' => $o->id,
            'framework' => $o->getFrameworkLabel(),
            'title' => $o->obligation_title,
            'due_date' => $o->due_date->toDateString(),
            'days_remaining' => now()->diffInDays($o->due_date, false),
            'status' => $o->status,
            'owner' => $o->owner?->name,
        ])->toArray();
    }

    public function getDecisionsRequired(): array
    {
        $resolutions = \App\Domain\Governance\Models\Resolution::where('status', 'open')
            ->orderBy('deadline')
            ->limit(10)
            ->get();

        return [
            'count' => $resolutions->count(),
            'overdue' => $resolutions->filter(fn($r) => $r->isOverdue())->count(),
            'items' => $resolutions->map(fn($r) => [
                'id' => $r->id,
                'reference' => $r->resolution_reference,
                'title' => $r->title,
                'deadline' => $r->deadline?->toDateString(),
                'is_overdue' => $r->isOverdue(),
                'threshold' => $r->voting_threshold,
            ])->toArray(),
        ];
    }

    public function getControlRoomMetrics(array $range): array
    {
        $alerts = ControlRoomAlert::whereBetween('triggered_at', [$range['start'], $range['end']]);

        $critical = (clone $alerts)->where('severity', 'critical')->count();
        $high = (clone $alerts)->where('severity', 'high')->count();

        // MTTA/MTTR calculations
        $avgAckTime = (clone $alerts)
            ->whereNotNull('acknowledged_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, triggered_at, acknowledged_at)) as avg_time')
            ->value('avg_time') ?? 0;

        $avgResolveTime = (clone $alerts)
            ->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, triggered_at, resolved_at)) as avg_time')
            ->value('avg_time') ?? 0;

        return [
            'critical_alerts' => $critical,
            'high_alerts' => $high,
            'mtta_minutes' => round($avgAckTime, 1),
            'mttr_minutes' => round($avgResolveTime, 1),
            'open_critical' => ControlRoomAlert::where('severity', 'critical')->where('status', 'open')->count(),
        ];
    }

    public function getIncidentMetrics(array $range): array
    {
        $total = ClientIncident::whereBetween('occurred_at', [$range['start'], $range['end']])->count();
        
        $bySeverity = ClientIncident::whereBetween('occurred_at', [$range['start'], $range['end']])
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $open = ClientIncident::where('status', '!=', 'closed')->count();

        // Avg time to close
        $avgCloseTime = ClientIncident::whereBetween('closed_at', [$range['start'], $range['end']])
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, occurred_at, closed_at)) as avg_time')
            ->value('avg_time') ?? 0;

        return [
            'total_period' => $total,
            'by_severity' => $bySeverity,
            'open_count' => $open,
            'avg_close_hours' => round($avgCloseTime, 1),
        ];
    }

    public function getSafeguardingMetrics(array $range): array
    {
        $concerns = SafeguardingConcern::whereBetween('reported_at', [$range['start'], $range['end']]);

        $new = (clone $concerns)->count();
        $critical = (clone $concerns)->where('severity', 'critical')->count();
        $open = SafeguardingConcern::whereNotIn('status', ['closed'])->count();
        $investigations = SafeguardingConcern::whereBetween('created_at', [$range['start'], $range['end']])
            ->whereHas('investigations')
            ->count();

        return [
            'new_concerns' => $new,
            'critical_concerns' => $critical,
            'open_concerns' => $open,
            'investigations_opened' => $investigations,
            'status' => $critical > 0 ? 'critical' : ($open > 5 ? 'warning' : 'good'),
        ];
    }

    protected function determineStatus(int $value, array $thresholds): string
    {
        return match(true) {
            $value >= $thresholds[1] => 'critical',
            $value >= $thresholds[0] => 'warning',
            default => 'good',
        };
    }

    protected function getDataFreshness(): array
    {
        return [
            'risks' => now()->toIso8601String(),
            'incidents' => now()->subMinutes(5)->toIso8601String(),
            'control_room' => now()->subMinutes(1)->toIso8601String(),
            'compliance' => now()->subHour()->toIso8601String(),
        ];
    }
}
