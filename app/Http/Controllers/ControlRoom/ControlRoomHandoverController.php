<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomHandoverScopeService;
use App\Services\ControlRoom\ControlRoomPreparedHandoverSnapshotService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ControlRoomHandoverController extends Controller
{
    public function show(
        Request $request,
        Shift $shift,
        ControlRoomHandoverScopeService $handoverScope,
        ControlRoomPreparedHandoverSnapshotService $preparedSnapshots,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        abort_unless($shift->status === 'active', 404, 'Only active shifts can be handed over.');

        $siteAccess = app(UserSiteAccessService::class);
        $shiftLead = $shift->shiftLead;
        $teamMembers = $shift->getTeamMemberUsers();
        $snapshot = $shift->handover_snapshot;
        $snapshotIssue = null;
        if ($shift->handover_status === Shift::HANDOVER_PREPARED) {
            abort_unless($this->canViewPreparedSnapshot($shift, $user), 403);
            try {
                $snapshot = $preparedSnapshots->validated($shift);
            } catch (ValidationException $exception) {
                $snapshot = null;
                $snapshotIssue = (string) collect($exception->errors())->flatten()->first();
            }
        }
        $draft = data_get($snapshot, 'draft', []);

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
            'handover_snapshot' => $snapshot,
            'draft' => is_array($draft) ? $draft : [],
            'incoming_lead' => $shift->handedOverTo ? [
                'id' => $shift->handedOverTo->id,
                'name' => $shift->handedOverTo->name,
            ] : null,
            'can_prepare' => $shift->handover_status === Shift::HANDOVER_NONE
                && (int) $shift->shift_lead_user_id === $user->id,
            'can_accept' => $shift->handover_status === Shift::HANDOVER_PREPARED
                && (int) $shift->handed_over_to_user_id === $user->id
                && $snapshotIssue === null,
        ];

        $hasPreparedScope = $shift->handover_status === Shift::HANDOVER_PREPARED
            && $snapshotIssue === null
            && is_array($snapshot)
            && is_array(data_get($snapshot, 'alerts'))
            && is_array(data_get($snapshot, 'carry_forward'));
        $scope = match (true) {
            $hasPreparedScope => [
                'criteria_at' => data_get($snapshot, 'criteria_at'),
                'criteria' => data_get($snapshot, 'criteria', []),
                'required_alerts' => data_get($snapshot, 'alerts', []),
                'carry_forward' => data_get($snapshot, 'carry_forward'),
            ],
            $snapshotIssue !== null => $this->emptyScope($shift),
            default => $handoverScope->build($shift, $user),
        };
        $requiredAlerts = collect($scope['required_alerts']);
        $carryForward = $scope['carry_forward'];
        $openAlertsCount = $requiredAlerts->count() + (int) $carryForward['total'];

        $pinnedNotes = $snapshotIssue === null
            ? $this->notes($shift, fn ($query) => $query->where('is_pinned', true))
            : [];
        $followupNotes = $snapshotIssue === null
            ? $this->notes($shift, fn ($query) => $query->where('requires_followup', true)->orderBy('followup_at'))
            : [];

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
            'requiredAlerts' => $requiredAlerts->values()->all(),
            'handoverCriteriaAt' => $scope['criteria_at'],
            'handoverCriteria' => $scope['criteria'],
            'carryForward' => $carryForward,
            'pinnedNotes' => $pinnedNotes,
            'followupNotes' => $followupNotes,
            'staff' => $staff->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
            ])->values()->all(),
            'eligibleLeads' => $eligibleLeads,
            'snapshotIssue' => $snapshotIssue,
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

    private function canViewPreparedSnapshot(Shift $shift, User $user): bool
    {
        if ($user->canDo('reports.viewAny')) {
            return true;
        }

        $participantIds = collect([
            ...$shift->memberUserIds(),
            $shift->handed_over_to_user_id,
        ])
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique();

        return $participantIds->contains($user->id);
    }

    /** @return array<string, mixed> */
    private function emptyScope(Shift $shift): array
    {
        return [
            'criteria_at' => $shift->handover_prepared_at?->toIso8601String()
                ?? now()->toIso8601String(),
            'criteria' => [],
            'required_alerts' => [],
            'carry_forward' => [
                'total' => 0,
                'by_severity' => [
                    'critical' => 0,
                    'high' => 0,
                    'medium' => 0,
                    'low' => 0,
                ],
                'by_queue' => [],
                'oldest_created_at' => null,
                'breached_count' => 0,
                'href' => '/control-room/alerts?lens=active&handover=carry-forward',
                'signature' => hash('sha256', '[]'),
            ],
        ];
    }
}
