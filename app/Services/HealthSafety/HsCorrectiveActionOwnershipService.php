<?php

namespace App\Services\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsCorrectiveActionOwnerChange;
use App\Models\HsEvent;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Accountable ownership changes are separate from starting or completing the work. */
final class HsCorrectiveActionOwnershipService
{
    private const PERMISSIONS = ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites'];

    public function __construct(
        private readonly UserSiteAccessService $sites,
        private readonly AuthorizationEvidenceLockService $authorization,
    ) {}

    public function eligible(User $user, HsEvent $event, ?CurrentAuthorizationReads $reads = null): bool
    {
        if (! $user->canDo('hazards.manage') || ! $user->canDo('hazards.view')) {
            return false;
        }
        $query = User::query()->staff()->whereNotNull('approved_at')->whereKey($user->id);
        if (! ($reads ? $reads->query($query) : $query)->exists()) {
            return false;
        }
        $siteIds = $this->sites->accessibleSiteIds($user, [], $reads);

        $siteEligible = $event->site_id === null
            ? $siteIds !== [] && $user->canDo('healthSafety.viewAllSites')
            : in_array((int) $event->site_id, $siteIds, true);

        return $siteEligible && $this->derivedSourceReadable($user, $event);
    }

    public function canManage(User $actor, HsCorrectiveAction $action): bool
    {
        return $action->hsEvent !== null && $this->eligible($actor, $action->hsEvent);
    }

    /** @return list<array{id: int, name: string}> */
    public function candidates(User $actor, HsCorrectiveAction $action, string $search = ''): array
    {
        return DB::transaction(fn () => $this->candidatesWithinSourceAuthority($actor, $action, $search));
    }

    private function candidatesWithinSourceAuthority(User $actor, HsCorrectiveAction $action, string $search): array
    {
        $action = HsCorrectiveAction::query()->lock('for share nowait')->findOrFail($action->id);
        $action->setRelation('hsEvent', HsEvent::query()->lock('for share nowait')->findOrFail($action->hs_event_id));
        abort_unless($action->status !== HsCorrectiveAction::STATUS_CLOSED && $this->canManage($actor, $action), 403);
        $query = User::query()->with(['roles.permissions', 'permissionOverrides'])
            ->whereKeyNot($action->assigned_to_user_id ?? 0)
            ->when($action->verified_by_user_id, fn ($query) => $query->whereKeyNot($action->verified_by_user_id))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')->orderBy('id');
        $this->sites->applyHsEventStaffScope($query, $action->hsEvent, $actor, []);
        $options = [];
        foreach ($query->lazy(100) as $candidate) {
            if ($this->eligible($candidate, $action->hsEvent)) {
                $options[] = ['id' => (int) $candidate->id, 'name' => $candidate->name];
                if (count($options) === 30) {
                    break;
                }
            }
        }

        return $options;
    }

    public function assign(User $actor, HsCorrectiveAction $record, array $input): HsCorrectiveActionOwnerChange
    {
        $input['reason'] = trim((string) ($input['reason'] ?? ''));
        $data = Validator::make($input, [
            'owner_user_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'request_uuid' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $hash = hash('sha256', json_encode([(int) $actor->id, (int) $record->id,
            (int) $data['owner_user_id'], (int) $data['expected_revision'], $data['reason']], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $record, $data, $hash) {
                $action = HsCorrectiveAction::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $action, $data, $hash) {
                    $event = $reads->query(HsEvent::query())->findOrFail($action->hs_event_id);
                    $actor = $this->authorization->lockForUserWithoutWaiting($actor, self::PERMISSIONS);
                    abort_unless($this->eligible($actor, $event, $reads), 403);
                    $existing = HsCorrectiveActionOwnerChange::query()->where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
                    if ($existing) {
                        if (! hash_equals($existing->request_hash, $hash)) {
                            throw ValidationException::withMessages(['request_uuid' => 'This request has already been used. Review the current action before trying again.']);
                        }

                        return $existing;
                    }
                    if ($action->status === HsCorrectiveAction::STATUS_CLOSED) {
                        throw ValidationException::withMessages(['owner_user_id' => 'A closed action keeps its historical owner.']);
                    }
                    if ((int) $action->owner_revision !== (int) $data['expected_revision']) {
                        throw ValidationException::withMessages(['expected_revision' => 'The owner changed while you were working. Review the current owner before trying again.']);
                    }
                    $ownerId = (int) $data['owner_user_id'];
                    if ((int) $action->assigned_to_user_id === $ownerId) {
                        throw ValidationException::withMessages(['owner_user_id' => 'This person already owns the action.']);
                    }
                    if ((int) $action->verified_by_user_id === $ownerId) {
                        throw ValidationException::withMessages(['owner_user_id' => 'Choose someone other than the independent verifier.']);
                    }
                    if (! $reads->query(User::query())->whereKey($ownerId)->exists()) {
                        throw ValidationException::withMessages(['owner_user_id' => 'Choose approved, current H&S staff who can read and manage this action at its site.']);
                    }
                    $owner = $this->authorization->lockForUserWithoutWaiting($ownerId, self::PERMISSIONS);
                    if (! $this->eligible($owner, $event, $reads)) {
                        throw ValidationException::withMessages(['owner_user_id' => 'Choose approved, current H&S staff who can read and manage this action at its site.']);
                    }
                    $previous = $action->assigned_to_user_id ? $reads->query(User::query())->find($action->assigned_to_user_id) : null;
                    $change = HsCorrectiveActionOwnerChange::create([
                        'action_id' => $action->id, 'revision' => $action->owner_revision + 1,
                        'previous_owner_user_id' => $previous?->id, 'previous_owner_name' => $previous?->name,
                        'owner_user_id' => $owner->id, 'owner_name' => $owner->name,
                        'actor_user_id' => $actor->id, 'actor_name' => $actor->name,
                        'reason' => $data['reason'], 'request_uuid' => $data['request_uuid'],
                        'request_hash' => $hash, 'created_at' => now(),
                    ]);
                    $action->forceFill(['assigned_to_user_id' => $owner->id, 'assigned_by_user_id' => $actor->id,
                        'assigned_at' => $change->created_at, 'owner_revision' => $change->revision, 'updated_by' => $actor->id])->save();
                    AuditLogger::logOrFail('health_safety.corrective_action.owner_changed', $action, [
                        'actor_id' => (int) $actor->id, 'change_id' => $change->id, 'revision' => $change->revision,
                    ]);
                    // Database-only delivery is committed atomically with the ownership
                    // receipt. Never copy incident/source text into a retained notification.
                    foreach (['incoming' => $owner, 'outgoing' => $previous] as $role => $recipient) {
                        if (! $recipient || ! $this->derivedSourceReadable($recipient, $event)) {
                            continue;
                        }
                        $recipient->notify(new AppEventNotification([
                            'title' => 'Corrective action ownership changed',
                            'message' => $role === 'incoming' ? 'You are now accountable for '.$action->reference_number.'.' : 'Responsibility for '.$action->reference_number.' has been handed over.',
                            'type' => 'health_safety_corrective_action_owner_changed',
                            'hs_corrective_action_id' => $action->id, 'change_id' => $change->id,
                            'url' => '/health-safety/corrective-actions/'.$action->id,
                        ]));
                    }
                    DB::afterCommit(function () use ($actor, $owner, $previous): void {
                        try {
                            foreach (array_unique(array_filter([$actor->id, $owner->id, $previous?->id])) as $id) {
                                Cache::forget("tasks.nav.{$id}");
                            }
                        } catch (\Throwable $error) {
                            // A badge refresh failure cannot undo the committed handover.
                            report($error);
                        }
                    });

                    return $change;
                });
            }, 3);
        } catch (\Illuminate\Database\DeadlockException) {
                // Inside a transaction Laravel raises a deadlock (1213) as DeadlockException, not QueryException.
                throw ValidationException::withMessages(['expected_revision' => 'Access or ownership is being updated. Your entries have been kept; review the action and try again.']);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['expected_revision' => 'Access or ownership is being updated. Your entries have been kept; review the action and try again.']);
            }
            if ((int) ($error->errorInfo[1] ?? 0) === 1062 && str_contains((string) ($error->errorInfo[2] ?? ''), 'request_uuid')) {
                throw ValidationException::withMessages(['request_uuid' => 'This request has already been used. Review the current action before trying again.']);
            }
            throw $error;
        }
    }

    /** Ordinary sources retain their existing eligibility and notification policy. */
    private function derivedSourceReadable(User $viewer, HsEvent $event): bool
    {
        return DB::transaction(function () use ($viewer, $event): bool {
            $query = HsEvent::query()->whereKey($event->id)->lock('for share nowait');
            app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $viewer);

            return $query->exists();
        });
    }

    public function present(User $viewer, HsCorrectiveAction $action, bool $participant): array
    {
        $action->loadMissing('assignedTo');
        $closed = $action->status === HsCorrectiveAction::STATUS_CLOSED;
        $owner = $action->assignedTo;
        $eligible = $owner && $action->hsEvent && $this->eligible($owner, $action->hsEvent);

        return [
            'revision' => (int) $action->owner_revision,
            'state' => $closed ? 'historical' : ($owner ? ($eligible ? 'assigned' : 'replacement_needed') : 'unassigned'),
            'can_assign' => ! $closed && $this->canManage($viewer, $action),
            'history' => $participant ? $action->ownerChanges->map(fn ($change) => [
                'id' => $change->id, 'previous_owner' => $change->previous_owner_name, 'owner' => $change->owner_name,
                'changed_by' => $change->actor_name, 'changed_at' => $change->created_at->toIso8601String(), 'reason' => $change->reason,
            ])->all() : [],
        ];
    }
}
