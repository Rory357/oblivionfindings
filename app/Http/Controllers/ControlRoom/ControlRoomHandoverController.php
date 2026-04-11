<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomHandoverController extends Controller
{
    /**
     * Display the multi-step handover wizard for a shift.
     */
    public function show(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        abort_unless($shift->status === 'active', 404, 'Only active shifts can be handed over.');
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();

        // Shift data with lead and team
        $shiftLead = $shift->shiftLead;
        $teamMembers = $shift->getTeamMemberUsers();

        $shiftData = [
            'id' => $shift->id,
            'name' => $shift->name,
            'starts_at' => $shift->starts_at->toISOString(),
            'ends_at' => $shift->ends_at?->toISOString(),
            'status' => $shift->status,
            'shift_lead' => $shiftLead ? [
                'id' => $shiftLead->id,
                'name' => $shiftLead->name,
            ] : null,
            'team_members' => $teamMembers->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])->values()->all(),
            'open_alerts_at_start' => $shift->open_alerts_at_start ?? 0,
            'alerts_created' => $shift->alerts_created ?? 0,
            'alerts_resolved' => $shift->alerts_resolved ?? 0,
            'alerts_escalated' => $shift->alerts_escalated ?? 0,
            'duration_minutes' => $shift->getDuration(),
        ];

        // Open alerts summary
        $openAlertsBase = ControlRoomAlert::unresolved();
        $siteAccess->applyAlertScope($openAlertsBase, $user, $bypassPermissions);

        $openAlertsCount = (clone $openAlertsBase)->count();
        $criticalAlerts = (clone $openAlertsBase)
            ->where('severity', 'critical')
            ->select(['id', 'alert_type', 'severity', 'triggered_at'])
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn (ControlRoomAlert $a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'triggered_at' => $a->triggered_at?->toISOString(),
            ])->all();

        $highAlerts = (clone $openAlertsBase)
            ->where('severity', 'high')
            ->select(['id', 'alert_type', 'severity', 'triggered_at'])
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn (ControlRoomAlert $a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'triggered_at' => $a->triggered_at?->toISOString(),
            ])->all();

        $criticalCount = (clone $openAlertsBase)->where('severity', 'critical')->count();
        $highCount = (clone $openAlertsBase)->where('severity', 'high')->count();

        // Pinned operator notes from this shift
        $pinnedNotes = OperatorNote::where('shift_id', $shift->id)
            ->where('is_pinned', true)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OperatorNote $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'content' => $n->content,
                'is_pinned' => $n->is_pinned,
                'requires_followup' => $n->requires_followup,
                'followup_at' => $n->followup_at?->toISOString(),
                'user' => $n->user ? ['id' => $n->user->id, 'name' => $n->user->name] : null,
                'created_at' => $n->created_at->toISOString(),
            ])->all();

        // Notes requiring followup
        $followupNotes = OperatorNote::where('shift_id', $shift->id)
            ->where('requires_followup', true)
            ->with('user:id,name')
            ->orderBy('followup_at')
            ->get()
            ->map(fn (OperatorNote $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'content' => $n->content,
                'is_pinned' => $n->is_pinned,
                'requires_followup' => $n->requires_followup,
                'followup_at' => $n->followup_at?->toISOString(),
                'user' => $n->user ? ['id' => $n->user->id, 'name' => $n->user->name] : null,
                'created_at' => $n->created_at->toISOString(),
            ])->all();

        // Available staff for incoming shift
        $staff = User::staff()
            ->tap(fn ($staffQuery) => $siteAccess->applyStaffScope($staffQuery, $user, $bypassPermissions))
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])->all();

        return Inertia::render('control-room/shifts/handover', [
            'shift' => $shiftData,
            'openAlertsCount' => $openAlertsCount,
            'criticalAlertsCount' => $criticalCount,
            'highAlertsCount' => $highCount,
            'criticalAlerts' => $criticalAlerts,
            'highAlerts' => $highAlerts,
            'pinnedNotes' => $pinnedNotes,
            'followupNotes' => $followupNotes,
            'staff' => $staff,
        ]);
    }

    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
