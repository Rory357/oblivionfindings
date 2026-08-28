<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertWorklistPresenter;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomMyTasksController extends Controller
{
    public function __construct(
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {}

    public function __invoke(Request $request, AlertWorklistPresenter $presenter)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        // My Alerts: unresolved alerts assigned to me
        $myAlerts = $this->visibleAlerts($user)
            ->where('assigned_to_user_id', $user->id)
            ->unresolved()
            ->with(['sla', 'asset:id,name,asset_tag', 'client:id,first_name,last_name'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get()
            ->map(fn (ControlRoomAlert $alert) => $presenter->present($alert, $user) + [
                // Compatibility alias retained for the established page contract.
                'sla_status' => $alert->sla?->isApplicable() ? $alert->sla->getStatus() : null,
            ])
            ->values();

        // My Follow-ups: operator notes I created that need followup
        $myFollowups = OperatorNote::where('user_id', $user->id)
            ->where('requires_followup', true)
            ->whereHas('alert', fn (Builder $alert) => $this->alertAccess->applyVisibleScope($alert, $user))
            ->with(['alert:id,reference_number,alert_type,severity,status'])
            ->orderBy('followup_at')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn (OperatorNote $n) => [
                'id' => $n->id,
                'content' => $n->content,
                'type' => $n->type,
                'followup_at' => optional($n->followup_at)->toISOString(),
                'created_at' => optional($n->created_at)->toISOString(),
                'alert' => $n->alert ? [
                    'id' => $n->alert->id,
                    'reference_number' => $n->alert->reference_number,
                    'alert_type' => $n->alert->alert_type,
                    'severity' => $n->alert->severity,
                    'status' => $n->alert->status,
                ] : null,
            ])
            ->values();

        // My Shift: current active shift where user is lead or team member
        $activeShift = Shift::where('status', 'active')
            ->where(function ($q) use ($user) {
                $q->where('shift_lead_user_id', $user->id)
                    ->orWhereJsonContains('team_members', $user->id);
            })
            ->latest('starts_at')
            ->first();

        $myShift = null;
        if ($activeShift) {
            $isLead = $activeShift->shift_lead_user_id === $user->id;
            $myShift = [
                'id' => $activeShift->id,
                'name' => $activeShift->name,
                'role' => $isLead ? 'Lead' : 'Team Member',
                'starts_at' => optional($activeShift->starts_at)->toISOString(),
                'duration_minutes' => $activeShift->getDuration(),
                'alerts_created' => $activeShift->alerts_created ?? 0,
                'alerts_resolved' => $activeShift->alerts_resolved ?? 0,
                'alerts_escalated' => $activeShift->alerts_escalated ?? 0,
            ];
        }

        // Stats
        $myOpenCount = $this->visibleAlerts($user)
            ->where('assigned_to_user_id', $user->id)
            ->unresolved()
            ->count();

        $myResolvedToday = $this->visibleAlerts($user)
            ->where('assigned_to_user_id', $user->id)
            ->where('status', 'resolved')
            ->whereDate('resolved_at', now()->toDateString())
            ->count();

        $myCritical = $this->visibleAlerts($user)
            ->where('assigned_to_user_id', $user->id)
            ->unresolved()
            ->where('severity', 'critical')
            ->count();

        return Inertia::render('control-room/my-tasks', [
            'my_alerts' => $myAlerts,
            'my_followups' => $myFollowups,
            'my_shift' => $myShift,
            'stats' => [
                'my_open' => $myOpenCount,
                'my_resolved_today' => $myResolvedToday,
                'my_critical' => $myCritical,
            ],
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'create' => $user->canDo('controlRoom.alerts.create'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
                'escalate' => $user->canDo('controlRoom.alerts.escalate'),
            ],
            // Workspace-over-list: when ?alert= is present the workspace dialog
            // opens over My Day (Inertia partial-reloads only this prop).
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,
        ]);
    }

    public function completeFollowup(Request $request, int $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $operatorNote = OperatorNote::where('id', $note)
            ->where('user_id', $user->id)
            ->where('requires_followup', true)
            ->whereHas('alert', fn (Builder $alert) => $this->alertAccess->applyVisibleScope($alert, $user))
            ->firstOrFail();

        $operatorNote->update(['requires_followup' => false]);

        AuditLogger::log('controlRoom.myTasks.followupComplete', $operatorNote, [
            'operator_note_id' => $operatorNote->id,
            'alert_id' => $operatorNote->alert_id,
        ]);

        return back()->with('success', 'Follow-up completed.');
    }

    private function visibleAlerts(User $user): Builder
    {
        $query = ControlRoomAlert::query();

        return $this->alertAccess->applyVisibleScope($query, $user);
    }
}
