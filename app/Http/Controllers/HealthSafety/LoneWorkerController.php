<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LoneWorkerController extends Controller
{
    /**
     * List active lone worker sessions and recent alerts.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['site_id', 'status', 'user_id']);

        $sessions = LoneWorkerSession::with(['user:id,name', 'site:id,name', 'client:id,first_name,last_name'])
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['user_id']), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString()
            ->through(function ($session) {
                $data = $session->toArray();
                if (isset($data['client']) && $data['client']) {
                    $data['client'] = [
                        'id' => $data['client']['id'],
                        'name' => ($data['client']['first_name'] ?? '') . ' ' . ($data['client']['last_name'] ?? ''),
                    ];
                }
                return $data;
            });

        // Stats
        $activeSessions = LoneWorkerSession::where('status', 'active')->count();
        $overdueCheckIns = LoneWorkerSession::where('status', 'overdue')->count();

        $alertsToday = LoneWorkerAlert::where('triggered_at', '>=', now()->startOfDay())->count();

        $emergencyAlerts = LoneWorkerAlert::where('alert_type', 'emergency')
            ->where('status', 'active')
            ->count();

        // Recent alerts
        $recentAlerts = LoneWorkerAlert::with([
                'loneWorkerSession.user:id,name',
                'loneWorkerSession.site:id,name',
                'loneWorkerSession.client:id,first_name,last_name',
            ])
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn ($alert) => [
                'id' => $alert->id,
                'session' => $alert->loneWorkerSession ? [
                    'id' => $alert->loneWorkerSession->id,
                    'user' => $alert->loneWorkerSession->user ? [
                        'id' => $alert->loneWorkerSession->user->id,
                        'name' => $alert->loneWorkerSession->user->name,
                    ] : null,
                    'site' => $alert->loneWorkerSession->site ? [
                        'id' => $alert->loneWorkerSession->site->id,
                        'name' => $alert->loneWorkerSession->site->name,
                    ] : null,
                    'client' => $alert->loneWorkerSession->client ? [
                        'id' => $alert->loneWorkerSession->client->id,
                        'name' => $alert->loneWorkerSession->client->first_name . ' ' . $alert->loneWorkerSession->client->last_name,
                    ] : null,
                    'started_at' => $alert->loneWorkerSession->started_at,
                    'expected_end_at' => $alert->loneWorkerSession->expected_end_at,
                    'last_check_in_at' => $alert->loneWorkerSession->last_check_in_at,
                    'status' => $alert->loneWorkerSession->status,
                    'activity_description' => $alert->loneWorkerSession->activity_description,
                    'check_in_interval_minutes' => $alert->loneWorkerSession->check_in_interval_minutes,
                    'location' => $alert->loneWorkerSession->location,
                ] : null,
                'type' => $alert->alert_type,
                'triggered_at' => $alert->triggered_at,
                'status' => $alert->status,
                'notes' => $alert->notes ?? null,
            ]);

        return Inertia::render('health-safety/lone-workers/index', [
            'sessions' => $sessions,
            'alerts' => $recentAlerts,
            'stats' => [
                'active_sessions' => $activeSessions,
                'overdue_check_ins' => $overdueCheckIns,
                'alerts_today' => $alertsToday,
                'emergency_alerts' => $emergencyAlerts,
            ],
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->first_name . ' ' . $c->last_name,
            ]),
            'filters' => $filters,
        ]);
    }

    /**
     * Start a new lone worker session.
     */
    public function startSession(Request $request): RedirectResponse
    {
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

        LoneWorkerSession::create(array_merge($validated, [
            'started_at' => now(),
            'last_check_in_at' => now(),
            'status' => 'active',
            'check_in_interval_minutes' => $validated['check_in_interval_minutes'] ?? 60,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return redirect()->route('health-safety.lone-workers.index')
            ->with('success', 'Lone worker session started successfully.');
    }

    /**
     * Record a check-in for an active session.
     */
    public function checkIn(Request $request, LoneWorkerSession $session): RedirectResponse
    {
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
            'updated_by' => $request->user()->id,
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
    public function endSession(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $session->update([
            'ended_at' => now(),
            'status' => 'completed',
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Lone worker session ended successfully.');
    }

    /**
     * Trigger emergency for an active session.
     */
    public function triggerEmergency(Request $request, LoneWorkerSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'emergency_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $session->update([
            'status' => 'emergency',
            'emergency_triggered_at' => now(),
            'emergency_notes' => $validated['emergency_notes'] ?? null,
            'updated_by' => $request->user()->id,
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
    public function acknowledgeAlert(Request $request, LoneWorkerAlert $alert): RedirectResponse
    {
        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'status' => 'acknowledged',
        ]);

        return redirect()->back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Resolve an alert with notes.
     */
    public function resolveAlert(Request $request, LoneWorkerAlert $alert): RedirectResponse
    {
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
