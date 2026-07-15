<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlRoomShiftHandoverService
{
    public function __construct(
        private readonly AlertWorklistQuery $worklist,
        private readonly AlertWorklistPresenter $presenter,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     */
    public function saveDraft(
        Shift $outgoing,
        array $draft,
        User $actor,
        int $expectedVersion,
    ): Shift {
        return DB::transaction(function () use ($outgoing, $draft, $actor, $expectedVersion): Shift {
            $locked = $this->lockShift($outgoing);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertOutgoingLead($locked, $actor);
            $this->assertEditable($locked);
            $normalisedDraft = $this->normaliseDraft($draft);
            $this->assertDraftAlertsVisible($normalisedDraft, $actor);

            $locked->forceFill([
                'handover_snapshot' => [
                    'draft' => $normalisedDraft,
                ],
                'handover_version' => $locked->handover_version + 1,
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * @param  list<int>  $reviewedAlertIds
     */
    public function prepare(
        Shift $outgoing,
        User $incomingLead,
        array $reviewedAlertIds,
        User $actor,
        int $expectedVersion,
    ): Shift {
        return DB::transaction(function () use ($outgoing, $incomingLead, $reviewedAlertIds, $actor, $expectedVersion): Shift {
            $locked = $this->lockShift($outgoing);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertOutgoingLead($locked, $actor);
            $this->assertEditable($locked);

            if (! $incomingLead->canDo('controlRoom.alerts.manage')) {
                throw ValidationException::withMessages([
                    'incoming_lead_user_id' => 'Choose a Control Room lead who can review and accept this handover.',
                ]);
            }

            $draft = data_get($locked->handover_snapshot, 'draft', []);
            if (! is_array($draft) || (int) ($draft['incoming_lead_user_id'] ?? 0) !== $incomingLead->id) {
                throw ValidationException::withMessages([
                    'incoming_lead_user_id' => 'Save the selected incoming lead before preparing the handover.',
                ]);
            }

            $alerts = $this->urgentAlertsFor($actor);
            $requiredIds = $alerts->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $reviewedIds = collect($reviewedAlertIds)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($reviewedIds !== $requiredIds) {
                throw ValidationException::withMessages([
                    'reviewed_alert_ids' => 'Review every current critical and high alert before preparing the handover.',
                ]);
            }

            $priorityAlertIds = collect($draft['priority_alert_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
            if ($priorityAlertIds->diff($requiredIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'priority_alert_ids' => 'Priority handover items must link to a reviewed critical or high alert.',
                ]);
            }

            $preparedAt = now();
            $alertSnapshots = $alerts
                ->map(fn (ControlRoomAlert $alert) => $this->presentAlertSnapshot($alert, $actor))
                ->values()
                ->all();

            $incomingTeamIds = collect($draft['incoming_team_members'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
            $incomingTeam = User::query()
                ->whereIn('id', $incomingTeamIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all();

            $snapshot = [
                'draft' => $draft,
                'prepared_by' => ['id' => $actor->id, 'name' => $actor->name],
                'prepared_at' => $preparedAt->toIso8601String(),
                'handover_notes' => (string) ($draft['handover_notes'] ?? ''),
                'incoming_shift' => [
                    'name' => filled($draft['incoming_shift_name'] ?? null)
                        ? trim((string) $draft['incoming_shift_name'])
                        : 'Shift '.$preparedAt->format('Y-m-d H:i'),
                    'lead' => ['id' => $incomingLead->id, 'name' => $incomingLead->name],
                    'team_members' => $incomingTeam,
                ],
                'reviewed_alert_ids' => $reviewedIds,
                'priority_alert_ids' => $priorityAlertIds->all(),
                'alerts' => $alertSnapshots,
            ];

            $locked->forceFill([
                'handover_status' => Shift::HANDOVER_PREPARED,
                'handover_snapshot' => $snapshot,
                'handed_over_to_user_id' => $incomingLead->id,
                'handover_prepared_at' => $preparedAt,
                'handover_version' => $locked->handover_version + 1,
            ])->save();

            AuditLogger::logOrFail('controlRoom.shift.handoverPrepared', $locked, [
                'actor_id' => $actor->id,
                'shift_id' => $locked->id,
                'incoming_lead_user_id' => $incomingLead->id,
                'reviewed_alert_ids' => $reviewedIds,
                'priority_alert_ids' => $priorityAlertIds->all(),
                'handover_version' => $locked->handover_version,
            ]);

            return $locked->fresh();
        });
    }

    public function accept(
        Shift $outgoing,
        User $actor,
        int $expectedVersion,
    ): Shift {
        return DB::transaction(function () use ($outgoing, $actor, $expectedVersion): Shift {
            $locked = $this->lockShift($outgoing);

            if ($locked->handover_status === Shift::HANDOVER_ACCEPTED) {
                if ((int) $locked->handed_over_to_user_id !== $actor->id) {
                    throw new AuthorizationException('Only the selected incoming lead can accept this handover.');
                }

                return $locked;
            }

            $this->assertVersion($locked, $expectedVersion);

            if ((int) $locked->handed_over_to_user_id !== $actor->id) {
                throw new AuthorizationException('Only the selected incoming lead can accept this handover.');
            }

            if ($locked->status !== 'active' || $locked->handover_status !== Shift::HANDOVER_PREPARED) {
                throw ValidationException::withMessages([
                    'handover' => 'This shift does not have a prepared handover to accept.',
                ]);
            }

            $activeShifts = Shift::query()->active()->lockForUpdate()->get(['id']);
            if ($activeShifts->contains(fn (Shift $shift) => $shift->id !== $locked->id)) {
                throw ValidationException::withMessages([
                    'handover' => 'Another active Control Room shift exists. Resolve it before accepting this handover.',
                ]);
            }

            $snapshot = $locked->handover_snapshot ?? [];
            $incoming = data_get($snapshot, 'incoming_shift', []);
            $teamMemberIds = collect(data_get($incoming, 'team_members', []))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $acceptedAt = now();

            $newShift = Shift::query()->create([
                'name' => filled($incoming['name'] ?? null)
                    ? trim((string) $incoming['name'])
                    : 'Shift '.$acceptedAt->format('Y-m-d H:i'),
                'starts_at' => $acceptedAt,
                'status' => 'active',
                'shift_lead_user_id' => $actor->id,
                'team_members' => $teamMemberIds,
                'open_alerts_at_start' => $this->worklist->forUser($actor, ['lens' => 'active'])->count(),
            ]);

            data_set($snapshot, 'incoming_shift.id', $newShift->id);
            data_set($snapshot, 'accepted_by', ['id' => $actor->id, 'name' => $actor->name]);
            data_set($snapshot, 'accepted_at', $acceptedAt->toIso8601String());

            $locked->forceFill([
                'status' => 'completed',
                'ends_at' => $acceptedAt,
                'open_alerts_at_end' => $this->worklist->forUser($actor, ['lens' => 'active'])->count(),
                'handover_status' => Shift::HANDOVER_ACCEPTED,
                'handover_snapshot' => $snapshot,
                'handover_accepted_at' => $acceptedAt,
                'handed_over_at' => $acceptedAt,
                'handover_version' => $locked->handover_version + 1,
            ])->save();

            AuditLogger::logOrFail('controlRoom.shift.handoverAccepted', $locked, [
                'actor_id' => $actor->id,
                'outgoing_shift_id' => $locked->id,
                'incoming_shift_id' => $newShift->id,
                'incoming_lead_user_id' => $actor->id,
                'handover_version' => $locked->handover_version,
            ]);

            return $locked->fresh();
        });
    }

    private function lockShift(Shift $shift): Shift
    {
        return Shift::query()->lockForUpdate()->findOrFail($shift->id);
    }

    private function assertVersion(Shift $shift, int $expectedVersion): void
    {
        if ($shift->handover_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'handover_version' => 'This handover changed in another session. Reload it before saving again.',
            ]);
        }
    }

    private function assertOutgoingLead(Shift $shift, User $actor): void
    {
        if ((int) $shift->shift_lead_user_id !== $actor->id) {
            throw new AuthorizationException('Only the outgoing shift lead can prepare this handover.');
        }
    }

    private function assertEditable(Shift $shift): void
    {
        if ($shift->status !== 'active' || $shift->handover_status !== Shift::HANDOVER_NONE) {
            throw ValidationException::withMessages([
                'handover' => 'This handover can no longer be edited.',
            ]);
        }
    }

    /** @return Collection<int, ControlRoomAlert> */
    private function urgentAlertsFor(User $actor)
    {
        return $this->worklist
            ->forUser($actor, ['lens' => 'active'])
            ->whereIn('control_room_alerts.severity', ['critical', 'high'])
            ->with(['tasks' => fn ($query) => $query
                ->whereNotIn('status', ['completed', 'cancelled', 'transferred'])
                ->orderBy('due_at')
                ->orderBy('id')])
            ->get();
    }

    /** @return array<string, mixed> */
    private function presentAlertSnapshot(ControlRoomAlert $alert, User $actor): array
    {
        $presented = $this->presenter->present($alert, $actor);
        $presented['tasks'] = $alert->tasks
            ->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->toIso8601String(),
                'href' => '/control-room/alerts/'.$alert->id,
            ])
            ->values()
            ->all();

        return $presented;
    }

    /** @param array<string, mixed> $draft */
    private function assertDraftAlertsVisible(array $draft, User $actor): void
    {
        $ids = collect([
            ...$draft['reviewed_alert_ids'],
            ...$draft['priority_alert_ids'],
        ])->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $visibleIds = $this->worklist
            ->forUser($actor, ['lens' => 'active'])
            ->whereIn('control_room_alerts.id', $ids)
            ->pluck('control_room_alerts.id')
            ->map(fn ($id) => (int) $id);

        if ($visibleIds->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'reviewed_alert_ids' => 'The draft contains an alert that is no longer active or available to you.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function normaliseDraft(array $draft): array
    {
        $normaliseIds = fn ($ids) => collect(is_array($ids) ? $ids : [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'handover_notes' => trim((string) ($draft['handover_notes'] ?? '')),
            'incoming_shift_name' => trim((string) ($draft['incoming_shift_name'] ?? '')),
            'incoming_lead_user_id' => filled($draft['incoming_lead_user_id'] ?? null)
                ? (int) $draft['incoming_lead_user_id']
                : null,
            'incoming_team_members' => $normaliseIds($draft['incoming_team_members'] ?? []),
            'reviewed_alert_ids' => $normaliseIds($draft['reviewed_alert_ids'] ?? []),
            'priority_alert_ids' => $normaliseIds($draft['priority_alert_ids'] ?? []),
        ];
    }
}
