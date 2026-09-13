<?php

namespace App\Services\MyDay;

use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Commands on the existing shift task record; the assigned shift remains the owner. */
class ShiftTaskWorkService
{
    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function canCreate(User $actor, Shift $shift): bool
    {
        return $this->canChange($actor, $shift, true);
    }

    public function canChange(User $actor, Shift $shift, bool $create = false): bool
    {
        try {
            $this->authorize($actor, $shift, create: $create);
            $this->assertOpen($shift);

            return true;
        } catch (HttpException|AuthorizationException|ValidationException $exception) {
            return false;
        }
    }

    public function authorize(User $actor, Shift $shift, bool $create = false): void
    {
        abort_unless((int) $shift->user_id === (int) $actor->id, 403);
        abort_unless($actor->canDo($create ? 'shifts.tasks.createSelf' : 'shifts.tasks.updateSelf')
            || $actor->canDo('shifts.update') || $actor->canDo('shifts.manageAny'), 403);
        abort_unless(Shift::query()->whereKey($shift->id)->visibleToFrontline()->exists(), 403);
        $this->sites->assertCanAccessShift($actor, $shift);
    }

    /** Only expose subjects whose ordinary client privacy policy allows access. */
    public function availableClients(User $actor, Shift $shift): Collection
    {
        $siteId = $shift->site_id ?? $shift->client?->site_id;
        if (! $siteId) {
            return collect();
        }

        return Client::query()->where('site_id', $siteId)
            ->where('status', '!=', 'archived')
            ->orderBy('first_name')->orderBy('last_name')->get()
            ->filter(fn (Client $client) => Gate::forUser($actor)->allows('view', $client))
            ->values();
    }

    public function create(User $actor, Shift $shift, array $input): ShiftTask
    {
        return DB::transaction(function () use ($actor, $shift, $input): ShiftTask {
            // All shift-task commands serialize against the canonical parent.
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->authorize($actor, $shift, create: true);
            $scope = $input['task_scope'];
            $clientId = $scope === 'client' ? (int) $input['client_id'] : null;
            if ($clientId !== null) {
                abort_unless($this->availableClients($actor, $shift)->contains('id', $clientId), 403);
            }

            $normal = [
                'label' => trim($input['label']),
                'task_scope' => $scope,
                'client_id' => $clientId,
                'when' => $input['when'],
                'scheduled_for' => $input['when'] === 'time'
                    ? CarbonImmutable::parse($input['scheduled_for'])->utc()->toIso8601String() : null,
                'created_by' => (int) $actor->id,
            ];
            if ($normal['label'] === '') {
                throw ValidationException::withMessages(['label' => 'Enter what needs to happen.']);
            }
            $steps = array_map(fn ($step) => ['id' => $step['id'], 'label' => trim($step['label'])], $input['steps'] ?? []);
            if (collect($steps)->contains(fn ($step) => $step['label'] === '')) {
                throw ValidationException::withMessages(['steps' => 'Give each step a name, or remove the empty step.']);
            }
            if ($steps !== []) {
                $normal['steps'] = $steps;
            }
            $hash = hash('sha256', json_encode($normal, JSON_THROW_ON_ERROR));
            $existing = $shift->tasks()->where('creation_key', $input['request_id'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->creation_hash, $hash)) {
                    throw ValidationException::withMessages([
                        'request_id' => 'This submission was already used for a different task. Refresh your day before adding another.',
                    ]);
                }

                return $existing->setRelation('shift', $shift);
            }

            $this->assertOpen($shift);
            $due = match ($input['when']) {
                'now' => now()->toImmutable()->utc(),
                'time' => CarbonImmutable::parse($normal['scheduled_for'])->utc(),
                default => null,
            };
            $workingNow = $input['when'] === 'now' && $shift->attendanceSessions()->where('user_id', $actor->id)->whereNull('clock_out_at')->exists();
            if ($due && ! $workingNow && (! $shift->starts_at || ! $shift->ends_at
                || $due->lt($shift->starts_at) || $due->gt($shift->ends_at))) {
                throw ValidationException::withMessages([
                    'scheduled_for' => 'Choose a time within this shift. Use “Any time today” for work without a set time.',
                ]);
            }

            $task = $shift->tasks()->create([
                'label' => $normal['label'],
                'task_scope' => $scope,
                'client_id' => $clientId,
                'created_by' => $actor->id,
                'creation_key' => $input['request_id'],
                'creation_hash' => $hash,
                'scheduled_at' => $due,
                'scheduled_time' => $due?->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('H:i'),
                'sort_order' => ((int) $shift->tasks()->max('sort_order')) + 1,
                'is_completed' => false,
                'version' => 0,
                'steps' => array_map(fn ($step) => [...$step, 'is_completed' => false, 'completed_at' => null, 'completed_by' => null], $steps),
            ]);
            AuditLogger::logOrFail('shift-task.created', $task, ['actor_id' => $actor->id, 'shift_id' => $shift->id]);

            return $task->setRelation('shift', $shift);
        }, attempts: 3);
    }

    public function complete(User $actor, ShiftTask $task, bool $completed, int $expectedVersion, bool $allowManageAny = false): ShiftTask
    {
        return DB::transaction(function () use ($actor, $task, $completed, $expectedVersion, $allowManageAny): ShiftTask {
            $shift = Shift::query()->lockForUpdate()->findOrFail($task->shift_id);
            $task = $shift->tasks()->lockForUpdate()->findOrFail($task->id);
            $task->setRelation('shift', $shift);
            if ($allowManageAny && $actor->canDo('shifts.manageAny')) {
                $this->sites->assertCanAccessShift($actor, $shift);
                abort_unless(in_array($shift->status, ['scheduled', 'in_progress'], true) && ! $shift->actual_ends_at, 422, 'This shift is closed.');
                $client = $task->task_scope === 'site' ? null : ($task->client ?? $shift->client);
                if ($client) {
                    abort_unless((int) $client->site_id === (int) ($shift->site_id ?? $shift->client?->site_id), 403);
                    Gate::forUser($actor)->authorize('view', $client);
                }
            } else {
                $this->authorizeTaskChange($actor, $task);
            }
            // A retried desired state never toggles the task back again.
            if ((bool) $task->is_completed === $completed) {
                return $task->setRelation('shift', $shift);
            }
            if ((int) $task->version !== $expectedVersion) {
                throw ValidationException::withMessages(['task' => 'This task changed. Refresh your day and check its latest state.']);
            }
            $this->assertStepsFinished($task, $completed);
            $task->update([
                'is_completed' => $completed,
                'completed_at' => $completed ? now() : null,
                'completed_by' => $completed ? $actor->id : null,
                'reminder_sent_at' => $completed ? $task->reminder_sent_at : null,
                'version' => $task->version + 1,
            ]);
            AuditLogger::logOrFail($completed ? 'shift-task.completed' : 'shift-task.reopened', $task, [
                'actor_id' => $actor->id, 'shift_id' => $shift->id, 'version' => $task->version,
            ]);

            return $task->setRelation('shift', $shift);
        }, attempts: 3);
    }

    public function authorizeTaskChange(User $actor, ShiftTask $task): void
    {
        if (! app(ShiftTaskHelpService::class)->isAcceptedHelper($actor, $task)) {
            $this->authorize($actor, $task->shift);
            $this->assertOpen($task->shift);
        }
        $client = $task->task_scope === 'site' ? null : ($task->client ?? $task->shift->client);
        if ($client) {
            abort_unless((int) $client->site_id === (int) ($task->shift->site_id ?? $task->shift->client?->site_id), 403);
            Gate::forUser($actor)->authorize('view', $client);
        }
    }

    public function assertStepsFinished(ShiftTask $task, bool $completed): void
    {
        if ($completed && collect($task->steps ?? [])->contains(fn ($step) => ! $step['is_completed'])) {
            throw ValidationException::withMessages(['task' => 'Finish the remaining steps before marking this task done.']);
        }
    }

    public function updateStep(User $actor, ShiftTask $snapshot, array $input): ShiftTask
    {
        return DB::transaction(function () use ($actor, $snapshot, $input) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($snapshot->shift_id);
            $task = $shift->tasks()->lockForUpdate()->findOrFail($snapshot->id)->setRelation('shift', $shift);
            $this->authorizeTaskChange($actor, $task);
            $steps = collect($task->steps ?? []);
            $at = $steps->search(fn ($step) => $step['id'] === $input['id']);
            $existing = $at === false ? null : $steps[$at];
            $action = $input['action'];
            if (($action === 'add' && $existing && $existing['label'] === trim($input['label']))
                || ($action === 'remove' && ! $existing)
                || ($action === 'complete' && $existing && (bool) $existing['is_completed'] === (bool) $input['is_completed'])) {
                return $task;
            }
            if ($task->is_completed || (int) $task->version !== (int) $input['expected_version']) {
                throw ValidationException::withMessages(['task' => 'This task changed or is already done. Refresh, and reopen it before changing steps.']);
            }
            if ($action === 'add') {
                if ($existing || $steps->count() >= 20 || trim($input['label']) === '') {
                    throw ValidationException::withMessages(['steps' => 'Use a unique step with a name. A task can have up to 20 steps.']);
                }
                $steps->push(['id' => $input['id'], 'label' => trim($input['label']), 'is_completed' => false, 'completed_at' => null, 'completed_by' => null]);
            } elseif ($action === 'remove') {
                if ($existing['is_completed']) {
                    throw ValidationException::withMessages(['steps' => 'Completed steps are kept with the task. Reopen the step before removing it.']);
                }
                $steps->forget($at);
            } else {
                abort_unless($existing, 404);
                $completed = (bool) $input['is_completed'];
                $steps[$at] = [...$existing, 'is_completed' => $completed, 'completed_at' => $completed ? now()->toIso8601String() : null, 'completed_by' => $completed ? $actor->id : null];
            }
            $task->update(['steps' => $steps->values()->all(), 'version' => $task->version + 1]);
            AuditLogger::logOrFail('shift-task.step-'.$action, $task, ['actor_id' => $actor->id, 'step_id' => $input['id']]);

            return $task;
        }, attempts: 3);
    }

    private function assertOpen(Shift $shift): void
    {
        if (! in_array($shift->status, ['scheduled', 'in_progress'], true) || $shift->actual_ends_at) {
            throw ValidationException::withMessages(['shift' => 'This shift is closed or unavailable. Its tasks cannot be changed.']);
        }
        $now = now(config('app.worker_timezone', 'Pacific/Auckland'))->toImmutable();
        $start = $shift->starts_at?->copy()->timezone($now->timezone);
        $hasOpenAttendance = $shift->attendanceSessions()->where('user_id', $shift->user_id)->whereNull('clock_out_at')->exists();
        if (! $start || ! $shift->ends_at || (! $hasOpenAttendance && ! $start->isSameDay($now) && ! $now->betweenIncluded($shift->starts_at, $shift->ends_at))) {
            throw ValidationException::withMessages(['shift' => 'Open your current shift to change its tasks.']);
        }
    }
}
