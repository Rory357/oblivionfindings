<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Integration Alert Controller
 *
 * Displays ControlRoomAlert records filtered to integration-originated sources.
 * Renders the same shared alerts page as ControlRoomAlertController, with the
 * same data shape and props — just pre-scoped to integration sources.
 */
class AlertController extends Controller
{
    /**
     * List integration-sourced alerts.
     *
     * Returns the exact same AlertItem shape and Props structure as
     * ControlRoomAlertController::index() so the shared frontend page
     * renders correctly.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.view'), 403);

        $query = ControlRoomAlert::with([
            'asset:id,name,asset_tag',
            'assignedTo:id,name,email',
            'client:id,first_name,last_name',
            'sla',
        ]);

        // Pre-scope to integration sources only
        $query->where('source', 'like', 'integration_%');

        // Site access scoping
        $siteAccess = app(UserSiteAccessService::class);
        $siteAccess->applyAlertScope($query, $user, ['reports.viewAny']);

        // Filters — same set as ControlRoomAlertController
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        if ($source = $request->input('source')) {
            // Allow further narrowing within integration sources
            $query->where('source', $source);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            if ($assignedTo === 'me') {
                $query->where('assigned_to_user_id', $user->id);
            } elseif ($assignedTo === 'unassigned') {
                $query->whereNull('assigned_to_user_id');
            } else {
                $query->where('assigned_to_user_id', (int) $assignedTo);
            }
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('alert_type', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%");
            });
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('triggered_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('triggered_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        // Sorting — same as canonical controller
        $sortField = $request->input('sort', 'triggered_at');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['triggered_at', 'severity', 'status', 'alert_type'];
        if (in_array($sortField, $allowedSorts, true)) {
            if ($sortField === 'severity') {
                $query->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low') ".($sortDir === 'desc' ? 'DESC' : 'ASC'));
            } else {
                $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
            }
        } else {
            $query->orderByDesc('triggered_at');
        }

        $paginated = $query->paginate(30)->withQueryString();

        // Same AlertItem shape as ControlRoomAlertController
        $alerts = $paginated->through(fn (ControlRoomAlert $alert) => [
            'id' => $alert->id,
            'source' => $alert->source,
            'alert_type' => $alert->alert_type,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'escalation_level' => $alert->escalation_level,
            'triggered_at' => optional($alert->triggered_at)->toISOString(),
            'asset' => $alert->asset ? [
                'id' => $alert->asset->id,
                'name' => $alert->asset->name,
                'asset_tag' => $alert->asset->asset_tag,
            ] : null,
            'assigned_to' => $alert->assignedTo ? [
                'id' => $alert->assignedTo->id,
                'name' => $alert->assignedTo->name,
            ] : null,
            'client_name' => $alert->client
                ? trim($alert->client->first_name.' '.$alert->client->last_name)
                : null,
            'sla_status' => $this->deriveSlaStatus($alert),
            'notes' => $alert->notes ? Str::limit($alert->notes, 120) : null,
        ]);

        // Stats — scoped to integration sources only
        $statsBase = ControlRoomAlert::where('source', 'like', 'integration_%');
        $siteAccess->applyAlertScope($statsBase, $user, ['reports.viewAny']);

        $stats = [
            'total' => (clone $statsBase)->count(),
            'open' => (clone $statsBase)->where('status', 'open')->count(),
            'critical' => (clone $statsBase)->where('severity', 'critical')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'assigned_to_me' => (clone $statsBase)->where('assigned_to_user_id', $user->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
            'unassigned' => (clone $statsBase)->whereNull('assigned_to_user_id')->whereNotIn('status', ['resolved', 'closed'])->count(),
        ];

        $staff = $this->assignableStaff($user);

        return Inertia::render('control-room/alerts/index', [
            'alerts' => $alerts,
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'search', 'date_from', 'date_to', 'sort', 'dir']),
            'stats' => $stats,
            'staff' => $staff,
            'basePath' => '/control-room/integration-alerts',
            'pageTitle' => 'Integration Alerts',
            'pageDescription' => 'Monitor and triage alerts raised by external integrations.',
            'pageBreadcrumbs' => [
                ['title' => 'Control Room', 'href' => '/control-room'],
                ['title' => 'Integration Alerts', 'href' => '/control-room/integration-alerts'],
            ],
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
            ],
        ]);
    }

    /**
     * Acknowledge an integration-sourced alert.
     */
    public function acknowledge(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessIntegrationAlert($user, $alert);

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_ACK)) {
            return back()->withErrors(['alert' => "Cannot acknowledge an alert in '{$alert->status}' status."]);
        }

        $alert->update([
            'status' => 'ack',
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
        ]);

        return redirect()->back();
    }

    /**
     * Assign an integration-sourced alert to a user.
     */
    public function assign(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);
        $this->assertCanAccessIntegrationAlert($user, $alert);

        if (! $alert->isActionable()) {
            return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);
        }

        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        app(UserSiteAccessService::class)->assertCanAssignControlRoomAlertToUser(
            $user,
            (int) $request->integer('user_id'),
            $this->alertBypassPermissions(),
            'You are not authorized to assign alerts to that staff member.',
        );

        $alert->update([
            'status' => 'triaging',
            'assigned_to_user_id' => $request->input('user_id'),
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        return redirect()->back();
    }

    /**
     * Close (resolve) an integration-sourced alert.
     */
    public function close(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessIntegrationAlert($user, $alert);

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_RESOLVED)) {
            return back()->withErrors(['alert' => "Cannot resolve an alert in '{$alert->status}' status."]);
        }

        $request->validate([
            'close_reason' => ['nullable', 'string'],
        ]);

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by_user_id' => $user->id,
            'resolution_code' => 'closed_via_integration_view',
            'notes' => $request->input('close_reason'),
        ]);

        return redirect()->back();
    }

    /**
     * Placeholder for incident creation from an alert.
     */
    public function createIncident(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessIntegrationAlert($user, $alert);

        return redirect()->back()->with('info', 'Incident linking will be available in a future update');
    }

    /**
     * Derive SLA status for a given alert (green/yellow/red/none).
     * Same logic as ControlRoomAlertController::deriveSlaStatus().
     */
    private function deriveSlaStatus(ControlRoomAlert $alert): ?string
    {
        if (! $alert->sla) {
            return null;
        }

        $sla = $alert->sla;
        if ($sla->resolution_breached || $sla->response_breached || $sla->acknowledge_breached) {
            return 'red';
        }

        $now = now();
        $deadlines = array_filter([
            $sla->acknowledge_deadline,
            $sla->response_deadline,
            $sla->resolution_deadline,
        ]);

        foreach ($deadlines as $deadline) {
            if ($deadline && $deadline->gt($now) && $deadline->diffInMinutes($now) <= 30) {
                return 'yellow';
            }
        }

        return 'green';
    }

    protected function assignableStaff(User $user): Collection
    {
        if (! $user->canDo('controlRoom.alerts.assign') && ! $user->canDo('controlRoom.alerts.manage')) {
            return collect();
        }

        $staffQuery = User::staff()->orderBy('name');

        $siteAccess = app(UserSiteAccessService::class);
        $siteAccess->applyControlRoomAssigneeScope($staffQuery, $user, $this->alertBypassPermissions());

        return $staffQuery->get(['id', 'name', 'email']);
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function assertCanAccessIntegrationAlert(User $user, ControlRoomAlert $alert): void
    {
        abort_unless(Str::startsWith($alert->source ?? '', 'integration_'), 404);

        app(UserSiteAccessService::class)->assertCanAccessAlert(
            $user,
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to access alerts for this site.',
        );
    }
}
