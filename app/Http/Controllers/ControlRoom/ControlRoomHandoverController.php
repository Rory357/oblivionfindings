<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\AlertWorklistPresenter;
use App\Services\ControlRoom\AlertWorklistQuery;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomHandoverController extends Controller
{
    public function show(
        Request $request,
        Shift $shift,
        AlertWorklistQuery $worklist,
        AlertWorklistPresenter $presenter,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        abort_unless($shift->status === 'active', 404, 'Only active shifts can be handed over.');

        $siteAccess = app(UserSiteAccessService::class);
        $shiftLead = $shift->shiftLead;
        $teamMembers = $shift->getTeamMemberUsers();
        $draft = data_get($shift->handover_snapshot, 'draft', []);

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
            'team_members' => $teamMembers->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
            ])->values()->all(),
            'open_alerts_at_start' => $shift->open_alerts_at_start ?? 0,
            'alerts_created' => $shift->alerts_created ?? 0,
            'alerts_resolved' => $shift->alerts_resolved ?? 0,
            'alerts_escalated' => $shift->alerts_escalated ?? 0,
            'duration_minutes' => $shift->getDuration(),
            'handover_status' => $shift->handover_status,
            'handover_version' => $shift->handover_version,
            'handover_prepared_at' => $shift->handover_prepared_at?->toISOString(),
            'handover_snapshot' => $shift->handover_snapshot,
            'draft' => is_array($draft) ? $draft : [],
            'incoming_lead' => $shift->handedOverTo ? [
                'id' => $shift->handedOverTo->id,
                'name' => $shift->handedOverTo->name,
            ] : null,
            'can_prepare' => $shift->handover_status === Shift::HANDOVER_NONE
                && (int) $shift->shift_lead_user_id === $user->id,
            'can_accept' => $shift->handover_status === Shift::HANDOVER_PREPARED
                && (int) $shift->handed_over_to_user_id === $user->id,
        ];

        $activeAlerts = $worklist->forUser($user, ['lens' => 'active']);
        $openAlertsCount = (clone $activeAlerts)->count();
        $urgentAlerts = (clone $activeAlerts)
            ->whereIn('control_room_alerts.severity', ['critical', 'high'])
            ->with(['tasks' => fn ($query) => $query
                ->whereNotIn('status', ['completed', 'cancelled', 'transferred'])
                ->orderBy('due_at')
                ->orderBy('id')])
            ->get()
            ->map(function (ControlRoomAlert $alert) use ($presenter, $user): array {
                $row = $presenter->present($alert, $user);
                $row['tasks'] = $alert->tasks->map(fn ($task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'due_at' => $task->due_at?->toISOString(),
                ])->values()->all();

                return $row;
            })
            ->values();

        $pinnedNotes = $this->notes($shift, fn ($query) => $query->where('is_pinned', true));
        $followupNotes = $this->notes($shift, fn ($query) => $query->where('requires_followup', true)->orderBy('followup_at'));

        $staff = User::staff()
            ->tap(fn ($query) => $siteAccess->applyStaffScope($query, $user, $this->alertBypassPermissions()))
            ->with(['roles.permissions:id,key', 'permissionOverrides:id,key'])
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
        $eligibleLeads = $staff
            ->filter(fn (User $candidate) => $candidate->canDo('controlRoom.alerts.manage'))
            ->map(fn (User $candidate) => ['id' => $candidate->id, 'name' => $candidate->name])
            ->values()
            ->all();

        return Inertia::render('control-room/shifts/handover', [
            'shift' => $shiftData,
            'openAlertsCount' => $openAlertsCount,
            'criticalAlertsCount' => $urgentAlerts->where('severity', 'critical')->count(),
            'highAlertsCount' => $urgentAlerts->where('severity', 'high')->count(),
            'criticalAlerts' => $urgentAlerts->where('severity', 'critical')->values()->all(),
            'highAlerts' => $urgentAlerts->where('severity', 'high')->values()->all(),
            'pinnedNotes' => $pinnedNotes,
            'followupNotes' => $followupNotes,
            'staff' => $staff->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
            ])->values()->all(),
            'eligibleLeads' => $eligibleLeads,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function notes(Shift $shift, callable $scope): array
    {
        return OperatorNote::query()
            ->where('shift_id', $shift->id)
            ->tap($scope)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OperatorNote $note) => [
                'id' => $note->id,
                'type' => $note->type,
                'content' => $note->content,
                'is_pinned' => $note->is_pinned,
                'requires_followup' => $note->requires_followup,
                'followup_at' => $note->followup_at?->toISOString(),
                'user' => $note->user ? ['id' => $note->user->id, 'name' => $note->user->name] : null,
                'created_at' => $note->created_at->toISOString(),
            ])
            ->all();
    }

    /** @return list<string> */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
