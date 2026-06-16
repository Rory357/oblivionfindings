<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerAlert;
use App\Models\LoneWorkerSession;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Lone Worker session management and alert display.
 *
 * ARCHITECTURAL NOTE (PR4):
 * Operational lone worker alerts now flow through the canonical pipeline:
 *   LoneWorkerSignalService → SignalProcessingService → ControlRoomAlert
 *
 * ControlRoomAlert (source='lone_worker') is the operational source of truth.
 * LoneWorkerAlert is a LEGACY compatibility model — it is still written for
 * backward compatibility during the transition but does NOT drive operational
 * triage, SLA, escalation, or playbooks.
 *
 * Operators should triage and resolve lone worker alerts via the Control Room.
 * The legacy acknowledge/resolve actions on this controller are convenience
 * actions for the H&S view only.
 */
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

        // Stats — canonical ControlRoomAlert is the primary source for operational counts
        $activeSessions = LoneWorkerSession::where('status', 'active')->count();
        $overdueCheckIns = LoneWorkerSession::where('status', 'overdue')->count();

        // Canonical operational alerts today (source of truth)
        $alertsToday = ControlRoomAlert::where('source', 'lone_worker')
            ->where('triggered_at', '>=', now()->startOfDay())
            ->count();

        // Canonical unresolved emergency alerts (source of truth)
        $emergencyAlerts = ControlRoomAlert::where('source', 'lone_worker')
            ->where('alert_type', 'lone_worker_emergency')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        // Recent alerts — canonical only (new alerts). Legacy shown only for historical pre-PR4 data.
        $canonicalAlerts = ControlRoomAlert::where('source', 'lone_worker')
            ->with(['client:id,first_name,last_name', 'site:id,name'])
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn ($alert) => $this->mapCanonicalAlert($alert));

        // Only include legacy alerts that predate canonical migration (no canonical equivalent)
        $legacyAlerts = LoneWorkerAlert::with([
                'session.user:id,name',
                'session.site:id,name',
                'session.client:id,first_name,last_name',
            ])
            ->where('triggered_at', '<', now()->subDay()) // historical only
            ->orderByDesc('triggered_at')
            ->limit(10)
            ->get()
            ->map(fn ($alert) => $this->mapLegacyAlert($alert));

        $recentAlerts = $canonicalAlerts->merge($legacyAlerts)
            ->sortByDesc('triggered_at')
            ->take(20)
            ->values();

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
            'can_manage' => $request->user()?->canDo('hazards.manage') ?? false,
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

        if ($request->boolean('stay')) {
            return back()->with('success', 'Lone worker session started successfully.');
        }

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

            // Legacy alert (compatibility — will be removed in future cleanup)
            $session->alerts()->create([
                'alert_type' => 'emergency',
                'triggered_at' => now(),
                'status' => 'active',
            ]);

            // Canonical signal → Control Room (operational source of truth)
            app(LoneWorkerSignalService::class)->emitEmergency($session, $validated['notes'] ?? null);
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

        // Legacy alert (compatibility — will be removed in future cleanup)
        $session->alerts()->create([
            'alert_type' => 'emergency',
            'triggered_at' => now(),
            'status' => 'active',
        ]);

        // Canonical signal → Control Room (operational source of truth)
        app(LoneWorkerSignalService::class)->emitEmergency($session, $validated['emergency_notes'] ?? null);

        return redirect()->back()->with('success', 'Emergency alert sent to Control Room.');
    }

    /**
     * Acknowledge a legacy alert (compatibility action only).
     *
     * NOTE: This acknowledges the legacy LoneWorkerAlert record only.
     * The canonical operational alert lives in Control Room and must be
     * triaged there for SLA, escalation, and playbook tracking.
     */
    public function acknowledgeAlert(Request $request, LoneWorkerAlert $alert): RedirectResponse
    {
        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'status' => 'acknowledged',
        ]);

        return redirect()->back()
            ->with('success', 'Alert acknowledged. For operational triage and escalation, use the Control Room.');
    }

    /**
     * Resolve a legacy alert with notes (compatibility action only).
     *
     * NOTE: This resolves the legacy LoneWorkerAlert record only.
     * The canonical operational alert lives in Control Room and must be
     * resolved there for complete audit trail and SLA tracking.
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

        return redirect()->back()
            ->with('success', 'Alert resolved. Ensure the corresponding Control Room alert is also resolved.');
    }

    /**
     * Map a legacy LoneWorkerAlert to the shared alert display shape.
     * These are historical/transitional records only.
     */
    private function mapLegacyAlert(LoneWorkerAlert $alert): array
    {
        return [
            'id' => 'legacy_' . $alert->id,
            'session' => $alert->session ? [
                'id' => $alert->session->id,
                'user' => $alert->session->user ? [
                    'id' => $alert->session->user->id,
                    'name' => $alert->session->user->name,
                ] : null,
                'site' => $alert->session->site ? [
                    'id' => $alert->session->site->id,
                    'name' => $alert->session->site->name,
                ] : null,
                'client' => $alert->session->client ? [
                    'id' => $alert->session->client->id,
                    'name' => $alert->session->client->first_name . ' ' . $alert->session->client->last_name,
                ] : null,
                'started_at' => $alert->session->started_at,
                'expected_end_at' => $alert->session->expected_end_at,
                'last_check_in_at' => $alert->session->last_check_in_at,
                'status' => $alert->session->status,
                'activity_description' => $alert->session->activity_description,
                'check_in_interval_minutes' => $alert->session->check_in_interval_minutes,
                'location' => $alert->session->location,
            ] : null,
            'type' => $alert->alert_type,
            'triggered_at' => $alert->triggered_at,
            'status' => $alert->status,
            'notes' => $alert->resolution_notes ?? null,
            '_source' => 'legacy',
        ];
    }

    /**
     * Map a canonical ControlRoomAlert to the shared alert display shape.
     * These are the operational source of truth.
     */
    private function mapCanonicalAlert(ControlRoomAlert $alert): array
    {
        $ctx = $alert->context['normalized_data'] ?? [];

        return [
            'id' => 'cr_' . $alert->id,
            'session' => [
                'id' => $ctx['lone_worker_session_id'] ?? null,
                'user' => isset($ctx['worker_name']) ? [
                    'id' => $ctx['worker_user_id'] ?? null,
                    'name' => $ctx['worker_name'],
                ] : null,
                'site' => isset($ctx['site_name']) ? [
                    'id' => $ctx['site_id'] ?? null,
                    'name' => $ctx['site_name'],
                ] : null,
                'client' => isset($ctx['client_name']) ? [
                    'id' => $ctx['client_id'] ?? null,
                    'name' => $ctx['client_name'],
                ] : null,
                'started_at' => $ctx['started_at'] ?? null,
                'expected_end_at' => $ctx['expected_end_at'] ?? null,
                'last_check_in_at' => $ctx['last_check_in_at'] ?? null,
                'status' => null,
                'activity_description' => $ctx['activity_description'] ?? null,
                'check_in_interval_minutes' => null,
                'location' => $ctx['location'] ?? null,
            ],
            'type' => $alert->alert_type,
            'triggered_at' => $alert->triggered_at,
            'status' => match ($alert->status) {
                'open' => 'active',
                'ack' => 'acknowledged',
                'resolved', 'closed' => 'resolved',
                default => $alert->status,
            },
            'notes' => $alert->notes,
            '_source' => 'canonical',
        ];
    }
}
