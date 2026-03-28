<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LoneWorkerController extends Controller
{
    /**
     * List active lone worker sessions and recent alerts.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $tenantId = $user->tenant_id;
        $filters = $request->only(['site_id', 'status', 'user_id']);

        $query = \DB::table('lone_worker_sessions')
            ->join('users', 'lone_worker_sessions.user_id', '=', 'users.id')
            ->leftJoin('sites', 'lone_worker_sessions.site_id', '=', 'sites.id')
            ->leftJoin('clients', 'lone_worker_sessions.client_id', '=', 'clients.id')
            ->where('users.tenant_id', $tenantId)
            ->whereNull('lone_worker_sessions.deleted_at')
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('lone_worker_sessions.site_id', $filters['site_id']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('lone_worker_sessions.status', $filters['status']))
            ->when(!empty($filters['user_id']), fn ($q) => $q->where('lone_worker_sessions.user_id', $filters['user_id']));

        $sessions = (clone $query)
            ->select(
                'lone_worker_sessions.*',
                'users.name as user_name',
                'sites.name as site_name',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name'
            )
            ->orderByDesc('lone_worker_sessions.started_at')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $activeSessions = \DB::table('lone_worker_sessions')
            ->join('users', 'lone_worker_sessions.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->where('lone_worker_sessions.status', 'active')
            ->whereNull('lone_worker_sessions.deleted_at')
            ->count();

        $overdueCheckIns = \DB::table('lone_worker_sessions')
            ->join('users', 'lone_worker_sessions.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->where('lone_worker_sessions.status', 'overdue')
            ->whereNull('lone_worker_sessions.deleted_at')
            ->count();

        $alertsToday = \DB::table('lone_worker_alerts')
            ->join('lone_worker_sessions', 'lone_worker_alerts.lone_worker_session_id', '=', 'lone_worker_sessions.id')
            ->join('users', 'lone_worker_sessions.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->where('lone_worker_alerts.triggered_at', '>=', now()->startOfDay())
            ->whereNull('lone_worker_alerts.deleted_at')
            ->count();

        $emergencyAlerts = \DB::table('lone_worker_alerts')
            ->join('lone_worker_sessions', 'lone_worker_alerts.lone_worker_session_id', '=', 'lone_worker_sessions.id')
            ->join('users', 'lone_worker_sessions.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->where('lone_worker_alerts.alert_type', 'emergency')
            ->where('lone_worker_alerts.status', 'active')
            ->whereNull('lone_worker_alerts.deleted_at')
            ->count();

        // Recent alerts
        $recentAlerts = \DB::table('lone_worker_alerts')
            ->join('lone_worker_sessions', 'lone_worker_alerts.lone_worker_session_id', '=', 'lone_worker_sessions.id')
            ->join('users', 'lone_worker_sessions.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->whereNull('lone_worker_alerts.deleted_at')
            ->select(
                'lone_worker_alerts.*',
                'users.name as worker_name',
                'lone_worker_sessions.location'
            )
            ->orderByDesc('lone_worker_alerts.triggered_at')
            ->limit(20)
            ->get();

        $sites = \DB::table('sites')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = \DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/lone-workers/index', [
            'sessions' => $sessions,
            'recentAlerts' => $recentAlerts,
            'stats' => [
                'active_sessions' => $activeSessions,
                'overdue_check_ins' => $overdueCheckIns,
                'alerts_today' => $alertsToday,
                'emergency_alerts' => $emergencyAlerts,
            ],
            'sites' => $sites,
            'staff' => $staff,
            'filters' => $filters,
        ]);
    }

    /**
     * Start a new lone worker session.
     */
    public function startSession(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'expected_end_at' => ['required', 'date', 'after:now'],
            'activity_description' => ['nullable', 'string', 'max:2000'],
            'check_in_interval_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'location' => ['nullable', 'string', 'max:500'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $session = LoneWorkerSession::create(array_merge($validated, [
            'started_at' => now(),
            'last_check_in_at' => now(),
            'status' => 'active',
            'check_in_interval_minutes' => $validated['check_in_interval_minutes'] ?? 60,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        return redirect()->route('health-safety.lone-workers.index')
            ->with('success', 'Lone worker session started successfully.');
    }

    /**
     * Record a check-in for an active session.
     */
    public function checkIn(Request $request, LoneWorkerSession $session)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:ok,concern,emergency'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $session->checkIns()->create(array_merge($validated, [
            'checked_in_at' => now(),
            'status' => $validated['status'] ?? 'ok',
        ]));

        $session->update([
            'last_check_in_at' => now(),
            'status' => 'active',
            'updated_by' => $user->id,
        ]);

        // If check-in status is emergency, trigger emergency flow
        if (($validated['status'] ?? 'ok') === 'emergency') {
            $session->update([
                'status' => 'emergency',
                'emergency_triggered_at' => now(),
                'emergency_notes' => $validated['notes'] ?? null,
            ]);

            $session->alerts()->create([
                'alert_type' => 'emergency',
                'triggered_at' => now(),
                'status' => 'active',
            ]);
        }

        return redirect()->back()->with('success', 'Check-in recorded successfully.');
    }

    /**
     * End a lone worker session.
     */
    public function endSession(Request $request, LoneWorkerSession $session)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $session->update([
            'ended_at' => now(),
            'status' => 'completed',
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Lone worker session ended successfully.');
    }

    /**
     * Trigger emergency for an active session.
     */
    public function triggerEmergency(Request $request, LoneWorkerSession $session)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'emergency_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $session->update([
            'status' => 'emergency',
            'emergency_triggered_at' => now(),
            'emergency_notes' => $validated['emergency_notes'] ?? null,
            'updated_by' => $user->id,
        ]);

        $session->alerts()->create([
            'alert_type' => 'emergency',
            'triggered_at' => now(),
            'status' => 'active',
        ]);

        return redirect()->back()->with('success', 'Emergency alert triggered.');
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledgeAlert(Request $request, LoneWorkerAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user->id,
            'status' => 'acknowledged',
        ]);

        return redirect()->back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Resolve an alert with notes.
     */
    public function resolveAlert(Request $request, LoneWorkerAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:2000'],
        ]);

        $alert->update([
            'resolved_at' => now(),
            'resolution_notes' => $validated['resolution_notes'],
            'status' => 'resolved',
        ]);

        return redirect()->back()->with('success', 'Alert resolved.');
    }
}
