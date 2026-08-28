<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Integration Alert Controller
 *
 * Displays ControlRoomAlert records filtered to integration-originated sources.
 * Renders the same shared alerts page as ControlRoomAlertController, with the
 * same data shape and props — just pre-scoped to integration sources.
 */
class AlertController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

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
        $alertAccess = app(ControlRoomAlertAccessService::class);
        $alertAccess->applyVisibleScope($query, $user);

        // Filters — same set as ControlRoomAlertController
        $status = $request->input('status');
        if (filled($status) && $status !== 'all') {
            $query->where('status', $status);
        } else {
            $query->actionable();
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

        // Snooze — mirrors the canonical worklist: the Snoozed tab shows
        // currently-snoozed alerts, every other view hides them (auto-return
        // once the window elapses).
        if ($request->input('snoozed') === '1') {
            $query->snoozed();
        } else {
            $query->notSnoozed();
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
            'snoozed_until' => optional($alert->snoozed_until)->toISOString(),
            'notes' => $alert->notes ? Str::limit($alert->notes, 120) : null,
        ]);

        // Stats — scoped to integration sources only. The tab counts mirror the
        // worklist, which hides currently-snoozed alerts.
        $statsBase = ControlRoomAlert::where('source', 'like', 'integration_%');
        $alertAccess->applyVisibleScope($statsBase, $user);

        $stats = [
            'total' => (clone $statsBase)->notSnoozed()->count(),
            'open' => (clone $statsBase)->notSnoozed()->where('status', 'open')->count(),
            'critical' => (clone $statsBase)->notSnoozed()->where('severity', 'critical')->actionable()->count(),
            'assigned_to_me' => (clone $statsBase)->notSnoozed()->where('assigned_to_user_id', $user->id)->actionable()->count(),
            'unassigned' => (clone $statsBase)->notSnoozed()->whereNull('assigned_to_user_id')->actionable()->count(),
            'snoozed' => (clone $statsBase)->snoozed()->count(),
        ];

        $staff = $this->assignableStaff($user);

        return Inertia::render('control-room/alerts/index', [
            'alerts' => $alerts,
            'filters' => $request->only(['status', 'severity', 'source', 'assigned_to', 'search', 'date_from', 'date_to', 'sort', 'dir', 'snoozed']),
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
            // Workspace-over-list: the shared alerts page opens the alert
            // workspace via ?alert= on this view too.
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,
        ]);
    }

    /**
     * Acknowledge an integration-sourced alert.
     */
    public function acknowledge(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessIntegrationAlert($user, $alert);

        try {
            $lifecycle->acknowledge($alert, $user);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

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

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        DB::transaction(function () use ($alert, $data, $user): void {
            $lockedAlert = ControlRoomAlert::query()
                ->whereKey($alert->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanAccessIntegrationAlert($user, $lockedAlert);

            if (! $lockedAlert->isActionable()) {
                throw ValidationException::withMessages([
                    'alert' => "Cannot assign an alert in '{$lockedAlert->status}' status.",
                ]);
            }

            app(UserSiteAccessService::class)->assertCanAssignControlRoomAlertToUser(
                $user,
                (int) $data['user_id'],
                $this->alertBypassPermissions(),
                'You are not authorized to assign alerts to that staff member.',
            );

            $lockedAlert->update([
                'assigned_to_user_id' => (int) $data['user_id'],
                'assigned_at' => now(),
                'assigned_by_user_id' => $user->id,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return redirect()->back();
    }

    /**
     * Close (resolve) an integration-sourced alert.
     */
    public function close(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessIntegrationAlert($user, $alert);

        $data = $request->validate([
            'close_reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $lifecycle->resolve(
                $alert,
                $user,
                $data['close_reason'],
                'closed_via_integration_view',
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return redirect()->back();
    }

    /**
     * Derive SLA status for a given alert (green/yellow/red/none).
     * Same logic as ControlRoomAlertController::deriveSlaStatus().
     */
    private function deriveSlaStatus(ControlRoomAlert $alert): ?string
    {
        return match ($alert->sla?->getStatus()) {
            'breached' => 'red',
            'at_risk' => 'yellow',
            'on_track', 'resolved' => 'green',
            default => null,
        };
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

        app(ControlRoomAlertAccessService::class)->assertCanView(
            $alert,
            $user,
        );
    }
}
