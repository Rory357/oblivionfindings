<?php

namespace App\Domain\Shifts\Planning;

use App\Models\CoverageReservation;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\ShiftTask;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\NotificationService;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\ShiftReplacementService;
use App\Services\Sites\SiteChecklistScheduler;
use App\Support\ShiftTaskSupport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Existing single planning actions, with current command evidence and atomic results. */
class ShiftPlanningCommand
{
    public function __construct(private readonly ShiftPlanningEvidence $evidence) {}

    /** Receives the existing controller's validated fields. Recurring writers remain separate. */
    public function save(User $actor, array $data, ?Shift $bound = null): ShiftPlanningResult
    {
        $data = ShiftPlanningIntent::cleanStrings($data);
        try {
            $result = DB::transaction(function () use ($actor, $data, $bound): ShiftPlanningResult {
                app(WorkforceMutationGuard::class)->lock();
                $evidence = $this->evidence->lock($actor, $data, $bound);
                $actor = $evidence['actor'];
                $locked = $evidence['shift'];
                $attributes = $evidence['attributes'];
                $intent = $evidence['intent'];
                $controlled = $evidence['data'];
                $candidate = $locked ? clone $locked : new Shift;
                $candidate->fill($attributes)->setRelation('client', $evidence['client']);
                if ($candidate->starts_at->diffInHours($candidate->ends_at) > 24) {
                    throw ValidationException::withMessages(['ends_at' => 'Shift duration cannot exceed 24 hours.']);
                }
                $reservationPayload = [...$attributes, 'coverage_rule_id' => $data['coverage_rule_id'] ?? null,
                    'coverage_roles' => $intent['coverage_roles']];
                // Updates retain hold acquisition before the eligibility decision.
                // A known rejection may commit only the existing hold cleanup.
                $reservation = $locked ? $this->reservation($actor, $data, $reservationPayload, true) : null;
                $decision = null;
                $override = null;
                $userId = $candidate->user_id;
                $timesChanged = $locked && (! $candidate->starts_at->equalTo($locked->starts_at) || ! $candidate->ends_at->equalTo($locked->ends_at));
                $needsDecision = $userId && (! $locked || (int) $userId !== (int) $locked->user_id || $timesChanged
                    || array_key_exists('required_licence_class', $data) || array_key_exists('required_licence_endorsements', $data));
                if ($needsDecision) {
                    $decision = app(AssignmentEligibilityGateway::class)->decide($candidate, $evidence['users']->get((int) $userId));
                    try {
                        $decision->assertMayAssign('user_id', 'This staff member cannot be assigned to this shift.');
                    } catch (ValidationException $exception) {
                        if (! $locked) {
                            throw $exception;
                        }
                        $this->releaseRejectedHold($reservation);

                        return new ShiftPlanningResult($locked, (int) $actor->id, false, $intent, $evidence['source'], null, $decision->result?->toArray() ?? [], $exception->errors(), $exception->status);
                    }
                    if ($locked && $decision->isWarning()) {
                        // No Shift/task/override write precedes a rejection.
                        // The canonical reservation acquisition/release is retained.
                        $reason = null;
                        if (empty($data['override_acknowledged'])) {
                            $reason = 'eligibility_warning';
                        } elseif (! empty($decision->result?->overrideable_warnings)) {
                            abort_unless($actor->canDo('shifts.overrideEligibility'), 403, 'You do not have permission to override eligibility warnings.');
                            if (trim((string) ($data['override_reason'] ?? '')) === '') {
                                $reason = 'override_reason_required';
                            } else {
                                $override = ['user_id' => (int) $userId, 'overridden_by' => (int) $actor->id,
                                    'override_reason' => trim((string) $data['override_reason']),
                                    'rules_overridden' => collect($decision->result->overrideable_warnings)->pluck('rule')->values()->all(),
                                    'acknowledged_warnings' => $decision->result->overrideable_warnings];
                            }
                        }
                        if ($reason !== null) {
                            $this->releaseRejectedHold($reservation);

                            return new ShiftPlanningResult($locked, (int) $actor->id, false, $intent, $evidence['source'], $reason, $decision->result?->toArray() ?? []);
                        }
                    }
                }
                if (! $locked) {
                    $reservation = $this->reservation($actor, $data, $reservationPayload, false);
                }
                $previous = $locked?->starts_at?->copy();
                $before = $locked?->getRawOriginal();
                if ($locked) {
                    $locked->fill($attributes);
                    if ($locked->isDirty() && $locked->save() !== true) {
                        throw ValidationException::withMessages(['shift' => 'This Shift could not be saved. Refresh its current record before trying again.']);
                    }
                    $row = $locked;
                } else {
                    $row = Shift::create([...$attributes, 'created_by' => $actor->id]);
                }
                $row = $this->persisted($row, $attributes, $before, (int) $actor->id);
                if (! $locked) {
                    app(SiteChecklistScheduler::class)->ensureRunsForShiftLocalDay($row);
                }
                ShiftTaskSupport::clearRemindersForShiftStartChange($row, $previous);
                $tasksChanged = $this->tasks($row, $evidence['tasks'], $controlled, $locked !== null, $previous);
                if ($override) {
                    $record = ShiftEligibilityOverride::create(['shift_id' => $row->id, ...$override]);
                    $stored = $record->id ? ShiftEligibilityOverride::query()->whereKey($record->id)->lockForUpdate()->first() : null;
                    abort_unless($stored && (int) $stored->shift_id === (int) $row->id && (int) $stored->user_id === $override['user_id']
                        && (int) $stored->overridden_by === $override['overridden_by'] && $stored->override_reason === $override['override_reason']
                        && $stored->rules_overridden === $override['rules_overridden'] && $stored->acknowledged_warnings === $override['acknowledged_warnings'], 409, 'The eligibility override was not saved. No Shift changes were applied.');
                }
                app(CoverageReservationService::class)->fulfill($reservation, $row);
                if ($reservation) {
                    $stored = CoverageReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
                    abort_unless($stored && $stored->status === CoverageReservationService::STATUS_FULFILLED && (int) $stored->shift_id === (int) $row->id, 409, 'The coverage hold was not fulfilled. No Shift changes were applied.');
                }
                if ($locked && $row->user_id && $before['user_id'] && (int) $row->user_id !== (int) $before['user_id']) {
                    $replacementEvidence = $this->replacementEvidence($row, $actor, $evidence);
                    app(ShiftReplacementService::class)->resolveFromManualAssignment($row, (int) $row->user_id, $actor, $decision, $replacementEvidence);
                }
                $row = $this->persisted($row, $attributes, $before, (int) $actor->id);
                $changed = $before === null || $row->getRawOriginal() !== $before || $tasksChanged || $override !== null || $reservation !== null;
                if (! $locked || $this->noticeChanged($before, $row)) {
                    $this->noticeAfterCommit($actor, $row, $evidence['client'], $locked ? 'updated' : 'created');
                }

                return new ShiftPlanningResult($row, (int) $actor->id, $changed, $intent, $evidence['source'], null, $decision?->result?->toArray() ?? []);
            });
            if ($result->validationErrors !== []) {
                throw ValidationException::withMessages($result->validationErrors)->status($result->validationStatus);
            }

            return $result;
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages(['shift' => 'Current Shift access is busy. No changes were saved. Refresh the record and try again.']);
        }
    }

    /** Fresh editable source, using the same existing update/owner/Site authority. */
    public function editable(User $actor, Shift $bound): ShiftPlanningResult
    {
        try {
            return DB::transaction(function () use ($actor, $bound): ShiftPlanningResult {
                app(WorkforceMutationGuard::class)->lock();
                $evidence = $this->evidence->lock($actor, ['client_id' => $bound->client_id], $bound, false);
                $row = $evidence['shift'];
                $row->setRelation('client', $evidence['client'])->setRelation('staff', $row->user_id ? $evidence['users']->get((int) $row->user_id) : null)
                    ->setRelation('tasks', $evidence['tasks'])->setRelation('site', $evidence['site'])->setRelation('serviceContext', $evidence['context']);

                return new ShiftPlanningResult($row, (int) $evidence['actor']->id, false, [], $evidence['source']);
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages(['shift' => 'Current Shift access is busy. Refresh the record and try again.']);
        }
    }

    private function reservation(User $actor, array $data, array $payload, bool $updating): ?CoverageReservation
    {
        $token = $data['coverage_reservation_token'] ?? null;
        if ($token) {
            // Reuse the existing validation algorithm on a current locked token.
            $current = CoverageReservation::query()->where('reservation_token', $token)->lockForUpdate()->first();
            if (! $current || $current->status !== CoverageReservationService::STATUS_ACTIVE || (int) $current->reserved_by_user_id !== (int) $actor->id
                || ! $current->expires_at || $current->expires_at->lte(now())) {
                throw ValidationException::withMessages(['coverage_reservation_token' => 'This coverage hold has expired. Re-open the gap and try again.']);
            }
            if ((int) $current->site_id !== (int) $payload['site_id'] || (! empty($data['coverage_rule_id']) && (int) $current->coverage_requirement_id !== (int) $data['coverage_rule_id'])) {
                throw ValidationException::withMessages(['coverage_reservation_token' => 'This coverage hold no longer matches the selected shortage window.']);
            }
            if (! $current->window_starts_at?->equalTo($payload['starts_at'])) {
                throw ValidationException::withMessages(['coverage_reservation_token' => 'This coverage hold was created for a different start time.']);
            }
            if (! $current->window_ends_at?->equalTo($payload['ends_at'])) {
                throw ValidationException::withMessages(['coverage_reservation_token' => 'This coverage hold was created for a different end time.']);
            }

            return $current;
        }

        return app(CoverageReservationService::class)->reserveForCoveragePayload($actor, $payload, $updating ? 'shift_update' : 'shift_store');
    }

    private function releaseRejectedHold(?CoverageReservation $reservation): void
    {
        app(CoverageReservationService::class)->release($reservation);
        if ($reservation) {
            $stored = CoverageReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            abort_unless($stored && $stored->status === CoverageReservationService::STATUS_RELEASED
                && $stored->expires_at?->lte(now()), 409, 'The coverage hold could not be released. No Shift changes were applied.');
        }
    }

    private function persisted(Shift $row, array $expected, ?array $before, int $actorId): Shift
    {
        abort_unless($row->exists && $row->id, 409, 'The Shift was not saved.');
        $stored = Shift::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
        foreach ($expected as $field => $value) {
            $actual = $stored->{$field};
            if (in_array($field, ['starts_at', 'ends_at'], true)) {
                $actual = ShiftPlanningIntent::instant($actual);
                $value = ShiftPlanningIntent::instant($value);
            } elseif (in_array($field, ['client_id', 'site_id', 'service_context_id', 'user_id', 'expected_break_minutes'], true)) {
                $actual = $actual === null ? null : (int) $actual;
                $value = $value === null ? null : (int) $value;
            } elseif (in_array($field, ['is_sleepover', 'is_on_call', 'is_lone_worker'], true)) {
                $actual = (bool) $actual;
                $value = (bool) $value;
            } elseif (in_array($field, ['coverage_roles', 'required_licence_endorsements'], true)) {
                $actual = array_values($actual ?? []);
                $value = array_values($value ?? []);
            }
            abort_unless($actual === $value, 409, 'The saved Shift does not match the requested changes. No changes were applied.');
        }
        if ($before === null) {
            abort_unless((int) $stored->created_by === $actorId, 409, 'The saved Shift creator does not match this command.');
        } else {
            foreach (['created_by', 'shift_series_id', 'actual_starts_at', 'actual_ends_at', 'started_by', 'completed_by', 'respite_booking_id', 'organization_id', 'handover_waiver_reason', 'handover_waived_at', 'handover_waived_by'] as $field) {
                abort_unless(($stored->getRawOriginal($field) ?? null) === ($before[$field] ?? null), 409, 'The saved Shift changed protected source evidence. No changes were applied.');
            }
        }
        foreach ($row->getRelations() as $name => $relation) {
            $stored->setRelation($name, $relation);
        }

        return $stored;
    }

    private function tasks(Shift $row, Collection $old, array $data, bool $updating, mixed $previousStart): bool
    {
        if (! array_key_exists('tasks', $data)) {
            return $previousStart && ! $previousStart->equalTo($row->starts_at) && $old->contains(fn (ShiftTask $task) => $task->scheduled_time !== null && $task->reminder_sent_at !== null && ! $task->is_completed);
        }
        $baseline = $old->mapWithKeys(fn (ShiftTask $task) => [(int) $task->id => $task->getRawOriginal()]);
        $incoming = ShiftTaskSupport::normalizeInputs($data['tasks'] ?? []);
        $manual = $old->filter(fn (ShiftTask $task) => ! $task->creation_key && ! $task->source_handover_id)->keyBy('id');
        $keep = $incoming->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($updating) {
            ShiftTask::query()->where('shift_id', $row->id)->whereNull('creation_key')->whereNull('source_handover_id')
                ->when($keep !== [], fn ($query) => $query->whereNotIn('id', $keep))->delete();
        }
        $expected = [];
        foreach ($incoming as $task) {
            $existing = $updating && $task['id'] ? $manual->get((int) $task['id']) : null;
            $payload = Arr::only($task, ['label', 'scheduled_time', 'sort_order']);
            if ($existing) {
                if (ShiftTaskSupport::normalizeTime($existing->scheduled_time) !== $task['scheduled_time']) {
                    $payload['reminder_sent_at'] = null;
                }
                if ($existing->fill($payload)->isDirty() && $existing->save() !== true) {
                    abort(409, 'A Shift task was not saved. No Shift changes were applied.');
                }
                $saved = $existing;
            } else {
                $saved = ShiftTask::create(['shift_id' => $row->id, ...$payload]);
            }
            abort_unless($saved->exists && $saved->id, 409, 'A Shift task was not saved. No Shift changes were applied.');
            $expected[] = ['id' => (int) $saved->id, ...$task];
            $expected[array_key_last($expected)]['id'] = (int) $saved->id;
        }
        $stored = ShiftTask::query()->where('shift_id', $row->id)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
        $storedManual = $stored->filter(fn (ShiftTask $task) => ! $task->creation_key && ! $task->source_handover_id)->values();
        abort_unless($storedManual->count() === count($expected), 409, 'Saved Shift tasks do not match this command.');
        foreach ($storedManual as $index => $task) {
            $wanted = $expected[$index];
            abort_unless((int) $task->id === $wanted['id'] && $task->label === $wanted['label'] && ShiftTaskSupport::normalizeTime($task->scheduled_time) === $wanted['scheduled_time']
                && (int) $task->sort_order === $wanted['sort_order'], 409, 'Saved Shift tasks do not match this command.');
            if ($original = $baseline->get((int) $task->id)) {
                foreach (['is_completed', 'completed_at', 'completed_by', 'creation_key', 'source_handover_id'] as $field) {
                    abort_unless($task->getRawOriginal($field) === ($original[$field] ?? null), 409, 'Task completion or source evidence changed during this planning edit.');
                }
            }
        }
        foreach ($old->filter(fn (ShiftTask $task) => $task->creation_key || $task->source_handover_id) as $protected) {
            $actual = $stored->firstWhere('id', $protected->id);
            $original = $baseline->get((int) $protected->id);
            if ($previousStart && ! $previousStart->equalTo($row->starts_at) && $protected->scheduled_time !== null && $protected->reminder_sent_at !== null && ! $protected->is_completed) {
                $original['reminder_sent_at'] = null;
                // The existing bulk reminder clear also writes its model timestamp.
                $original['updated_at'] = $actual?->getRawOriginal('updated_at');
            }
            abort_unless($actual && $actual->getRawOriginal() === $original, 409, 'A source-owned Shift task changed during this planning edit.');
        }
        $row->setRelation('tasks', $stored);

        // Compare the captured raw baseline, not manual models mutated above.
        $beforeRows = $baseline->sortBy(fn (array $task) => sprintf('%012d-%012d', $task['sort_order'] ?? 0, $task['id']))->values()->all();

        return $stored->map(fn (ShiftTask $task) => $task->getRawOriginal())->all() !== $beforeRows;
    }

    /** Current translation of the existing replacement/request/position integrity predicates. */
    private function replacementEvidence(Shift $shift, User $actor, array $evidence): array
    {
        $staff = $evidence['users']->get((int) $shift->user_id);
        $shift->setRelation('staff', $staff)->setRelation('client', $evidence['client'])
            ->setRelation('serviceContext', $evidence['context'])->setRelation('site', $evidence['site']);
        $requests = ShiftReplacementRequest::query()->where('shift_id', $shift->id)->orderBy('id')->lockForUpdate()->get();
        $positions = ShiftOpenPosition::query()->where(fn ($query) => $query->where('shift_id', $shift->id)
            ->orWhereIn('replacement_request_id', $requests->modelKeys()))->orderBy('id')->lockForUpdate()->get();
        $userIds = $requests->flatMap(fn ($request) => [$request->current_staff_id, $request->requested_by,
            $request->replacement_user_id, $request->approved_by, $request->cancelled_by])
            ->merge($positions->flatMap(fn ($position) => [$position->claimed_by, $position->approved_by]))
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $users = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(User::query()->whereIn('id', $userIds)->orderBy('id'))->get()->keyBy('id'));
        foreach ($evidence['users'] as $user) {
            $users->put((int) $user->id, $user);
        }
        $requests = $requests->filter(function ($request) use ($users): bool {
            if (! $request->current_staff_id || ! $users->has((int) $request->current_staff_id)) {
                return false;
            }
            foreach (['requested_by', 'replacement_user_id', 'approved_by', 'cancelled_by'] as $field) {
                if ($request->{$field} !== null && ! $users->has((int) $request->{$field})) {
                    return false;
                }
            }

            return true;
        })->keyBy('id');
        $validPositions = $positions->filter(function ($position) use ($shift, $requests, $users): bool {
            if ((int) $position->shift_id !== (int) $shift->id
                || ($position->replacement_request_id !== null && ! $requests->has((int) $position->replacement_request_id))) {
                return false;
            }
            foreach (['claimed_by', 'approved_by'] as $field) {
                if ($position->{$field} !== null && ! $users->has((int) $position->{$field})) {
                    return false;
                }
            }

            return true;
        });
        $replacement = $requests->filter(fn ($request) => in_array($request->status, ['requested', 'claimed'], true))
            ->sortByDesc(fn ($request) => $request->requested_at?->format('Y-m-d H:i:s') ?? '')->first();
        if ($replacement) {
            $position = $positions->first(fn ($position) => (int) $position->replacement_request_id === (int) $replacement->id);
            // A malformed HasOne link cannot redirect the planning write to
            // another Shift or an invalid claimant/approver record.
            abort_unless(! $position || $validPositions->contains(fn ($valid) => (int) $valid->id === (int) $position->id),
                403, 'The current replacement position no longer belongs to this Shift.');
            $replacement->setRelation('shift', $shift)->setRelation('requester', $users->get((int) $replacement->requested_by))
                ->setRelation('currentStaff', $users->get((int) $replacement->current_staff_id))
                ->setRelation('replacementStaff', $users->get((int) $replacement->replacement_user_id))->setRelation('openPosition', $position);
        }

        return ['shift' => $shift, 'actor' => $actor, 'staff' => $staff, 'replacement' => $replacement, 'positions' => $validPositions];
    }

    private function noticeChanged(array $before, Shift $after): bool
    {
        foreach (['user_id', 'starts_at', 'ends_at', 'location', 'service_context_id', 'status'] as $field) {
            if ($before[$field] !== $after->getRawOriginal($field)) {
                return true;
            }
        }

        return false;
    }

    private function noticeAfterCommit(User $actor, Shift $shift, mixed $client, string $verb): void
    {
        if ($verb === 'created' && ! $shift->user_id) {
            return;
        }
        DB::afterCommit(static function () use ($actor, $shift, $client, $verb): void {
            try {
                app(NotificationService::class)->notifyCrud($actor, $verb, 'shift', $shift, $client, [
                    'title' => $verb === 'created' ? 'Shift created' : 'Shift updated',
                    'body' => $client ? "Client: {$client->first_name} {$client->last_name}" : null,
                    'url' => url("/operations/shifts/{$shift->id}"), 'target_user_ids' => $shift->user_id ? [(int) $shift->user_id] : [],
                ]);
            } catch (Throwable $exception) {
                try {
                    Log::warning('Failed to send Shift planning notification after commit', ['shift_id' => $shift->id, 'exception_class' => $exception::class]);
                } catch (Throwable) {
                }
            }
        });
    }
}
