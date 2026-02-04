<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientSupportPlan;
use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ComplianceDashboardController extends Controller
{
    /**
     * Apply an organisation scope only if the underlying table contains organization_id.
     * This keeps the dashboard safe across single-tenant and multi-tenant deployments.
     */
    private function scopeOrg(Builder $query, string $table, ?int $orgId): Builder
    {
        if ($orgId && Schema::hasColumn($table, 'organization_id')) {
            $query->where('organization_id', $orgId);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('compliance.view'), 403);

        $orgId = $user->organization_id ?? null;
        $today = Carbon::today();
        $from30 = Carbon::now()->subDays(30);
        $from14 = Carbon::now()->subDays(14);

        // ------------------------------
        // Incidents
        // ------------------------------
        $incidentsBase = $this->scopeOrg(ClientIncident::query(), 'client_incidents', $orgId);

        $openIncidents = (clone $incidentsBase)
            ->whereIn('status', ['submitted', 'reviewed'])
            ->count();

        $incidentBySeverity = (clone $incidentsBase)
            ->where('created_at', '>=', $from30)
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->get()
            ->map(fn ($r) => ['severity' => (string) $r->severity, 'total' => (int) $r->total])
            ->values();

        // ------------------------------
        // Controlled drugs discrepancies
        // ------------------------------
        $cdBase = $this->scopeOrg(ClientControlledDrugDiscrepancy::query(), 'client_controlled_drug_discrepancies', $orgId);

        $openCdDiscrepancies = (clone $cdBase)
            ->where('status', 'open')
            ->count();

        $cdTrend = (clone $cdBase)
            ->where('created_at', '>=', $from30)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->d, 'total' => (int) $r->total])
            ->values();

        // ------------------------------
        // MAR exceptions + trends
        // ------------------------------
        $marBase = $this->scopeOrg(ClientMedicationAdministration::query(), 'client_medication_administrations', $orgId);

        $marExceptionsToday = (clone $marBase)
            ->whereDate('scheduled_for', $today)
            ->whereIn('status', ['missed', 'refused', 'withheld'])
            ->count();

        $marTrend = (clone $marBase)
            ->where('scheduled_for', '>=', $from14)
            ->selectRaw('DATE(scheduled_for) as d,
                SUM(status = "given") as given_total,
                SUM(status = "missed") as missed_total,
                SUM(status = "refused") as refused_total,
                SUM(status = "withheld") as withheld_total')
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
            ->values();

        // ------------------------------
        // Break-glass usage
        // ------------------------------
        $bgBase = $this->scopeOrg(ClientBreakGlassAccess::query(), 'client_break_glass_accesses', $orgId);

        // Your current implementation logs access events; review workflow can be added later.
        // For now, show “uses in last 30 days”.
        $breakGlassLast30d = (clone $bgBase)
            ->where('created_at', '>=', $from30)
            ->count();

        // ------------------------------
        // Care plan reviews due (next 30 days)
        // ------------------------------
        $carePlanReviewsDue = (int) ClientSupportPlan::query()
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', Carbon::now()->addDays(30))
            ->count();

        // ------------------------------
        // Audit logs (30d)
        // ------------------------------
        $auditEvents30d = (int) $this->scopeOrg(AuditLog::query(), 'audit_logs', $orgId)
            ->where('created_at', '>=', $from30)
            ->count();

        // ------------------------------
        // Control Room Alerts
        // ------------------------------
        $controlRoomOpen = ControlRoomAlert::whereNotIn('status', ['resolved', 'closed'])->count();
        $controlRoomCritical = ControlRoomAlert::where('severity', 'critical')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();
        $controlRoomEscalated = ControlRoomAlert::where('escalation_level', '>', 0)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        // Recent alerts for quick view (last 5 unresolved)
        $recentAlerts = ControlRoomAlert::query()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 WHEN severity = 'high' THEN 1 WHEN severity = 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('triggered_at')
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'source' => $a->source,
                'triggered_at' => $a->triggered_at?->toISOString(),
            ]);

        // Alert trend for last 14 days
        $alertTrend = ControlRoomAlert::where('triggered_at', '>=', $from14)
            ->selectRaw('DATE(triggered_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn($r) => ['date' => (string) $r->d, 'total' => (int) $r->total])
            ->values();

        return inertia('compliance/index', [
            'kpis' => [
                'openIncidents' => $openIncidents,
                'openCdDiscrepancies' => $openCdDiscrepancies,
                'marExceptionsToday' => $marExceptionsToday,
                'breakGlassLast30d' => $breakGlassLast30d,
                'carePlanReviewsDue' => $carePlanReviewsDue,
                'auditEvents30d' => $auditEvents30d,
            ],
            'controlRoom' => [
                'open' => $controlRoomOpen,
                'critical' => $controlRoomCritical,
                'escalated' => $controlRoomEscalated,
                'recentAlerts' => $recentAlerts,
                'alertTrend' => $alertTrend,
            ],
            'charts' => [
                'incidentBySeverity' => $incidentBySeverity,
                'marTrend' => $marTrend,
                'cdTrend' => $cdTrend,
            ],
        ]);
    }
}
