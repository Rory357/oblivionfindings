<?php

namespace App\Services\MyDay;

use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use App\Support\ShiftTaskSupport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Assistance stays attached to its original shift task and has an explicit recipient. */
class ShiftTaskHelpService
{
    public function __construct(private readonly UserSiteAccessService $sites, private readonly ShiftTaskWorkService $work) {}

    public function eligible(User $person, ShiftTask $task): bool
    {
        try {
            $shift = $task->shift;
            if (! $shift || (int) $shift->user_id === (int) $person->id
                || $shift->status === 'cancelled'
                || ! Shift::query()->whereKey($shift->id)->visibleToFrontline()->exists()
                || ! ($person->canDo('shifts.tasks.updateSelf') || $person->canDo('shifts.update') || $person->canDo('shifts.manageAny'))) {
                return false;
            }
            $this->sites->assertCanAccessShift($person, $shift);
            $this->sites->assertCanUseCurrentStaffAtSite($person, $person->id, (int) ($shift->site_id ?? $shift->client?->site_id));
            $client = $task->task_scope === 'site' ? null : ($task->client ?? $shift->client);

            return ! $client || ((int) $client->site_id === (int) ($shift->site_id ?? $shift->client?->site_id)
                && Gate::forUser($person)->allows('view', $client));
        } catch (HttpException|AuthorizationException|ValidationException $e) {
            return false;
        }
    }

    public function recipients(User $actor, ShiftTask $task): Collection
    {
        $this->work->authorize($actor, $task->shift);
        $this->assertSubject($actor, $task);

        return $this->sites->applyStaffScope(User::query(), $actor)->orderBy('name')->get()
            ->filter(fn (User $person) => $this->eligible($person, $task))
            ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name])->values();
    }

    public function accepted(ShiftTask $task): bool
    {
        return $task->help_status === 'accepted' && $task->helpRecipient && $this->eligible($task->helpRecipient, $task);
    }

    public function isAcceptedHelper(User $actor, ShiftTask $task): bool
    {
        return (int) $task->help_requested_to === (int) $actor->id
            && $task->help_status === 'accepted' && $this->eligible($actor, $task);
    }

    public function inbox(User $actor): array
    {
        return ShiftTask::query()->where('help_requested_to', $actor->id)->where('is_completed', false)
            ->whereIn('help_status', ['requested', 'accepted'])
            ->whereHas('shift', fn ($query) => $this->sites->applyShiftScope($query->visibleToFrontline(), $actor))
            ->with(['shift.staff', 'client', 'helpRecipient'])->orderBy('help_requested_at')->get()
            ->filter(fn (ShiftTask $task) => $this->eligible($actor, $task))
            ->map(fn (ShiftTask $task) => [...ShiftTaskSupport::workPayload($task),
                'requested_by_name' => $task->shift->staff?->name,
                'person_name' => $task->task_scope === 'site' ? 'Whole site' : trim(($task->client ?? $task->shift->client)?->full_name ?? ''),
                'can_complete' => $task->help_status === 'accepted',
            ])->values()->all();
    }

    public function request(User $actor, ShiftTask $snapshot, int $recipientId, string $reason, int $version): ShiftTask
    {
        return DB::transaction(function () use ($actor, $snapshot, $recipientId, $reason, $version) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($snapshot->shift_id);
            $this->work->authorize($actor, $shift);
            abort_unless($this->work->canChange($actor, $shift), 422, 'This shift is closed.');
            $task = $shift->tasks()->lockForUpdate()->findOrFail($snapshot->id)->setRelation('shift', $shift);
            $this->assertSubject($actor, $task);
            $recipient = User::findOrFail($recipientId);
            abort_unless($this->eligible($recipient, $task), 403);
            $reason = trim($reason);
            if ($task->help_status === 'requested' && (int) $task->help_requested_to === $recipientId && $task->help_reason === $reason) {
                return $task;
            }
            $this->assertVersion($task, $version);
            if ($task->is_completed || $this->accepted($task)) {
                throw ValidationException::withMessages(['task' => 'This task is already done or someone has accepted responsibility. Refresh to see who is following it up.']);
            }
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Explain what is stopping the task and what help is needed.']);
            }
            $task->update(['help_requested_to' => $recipientId, 'help_reason' => $reason, 'help_status' => 'requested',
                'help_requested_at' => now(), 'help_responded_at' => null, 'version' => $task->version + 1]);
            AuditLogger::logOrFail('shift-task.help-requested', $task, ['actor_id' => $actor->id, 'recipient_id' => $recipientId]);

            return $task;
        }, attempts: 3);
    }

    public function respond(User $actor, ShiftTask $snapshot, string $response, int $version): ShiftTask
    {
        abort_unless(in_array($response, ['accepted', 'declined'], true), 422);

        return DB::transaction(function () use ($actor, $snapshot, $response, $version) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($snapshot->shift_id);
            $task = $shift->tasks()->lockForUpdate()->findOrFail($snapshot->id)->setRelation('shift', $shift);
            abort_unless((int) $task->help_requested_to === (int) $actor->id && $this->eligible($actor, $task), 403);
            if ($task->help_status === $response) {
                return $task;
            }
            $this->assertVersion($task, $version);
            if ($task->is_completed || $task->help_status !== 'requested') {
                throw ValidationException::withMessages(['task' => 'This request changed. Refresh before responding.']);
            }
            $task->update(['help_status' => $response, 'help_responded_at' => now(), 'version' => $task->version + 1]);
            AuditLogger::logOrFail('shift-task.help-'.$response, $task, ['actor_id' => $actor->id]);

            return $task;
        }, attempts: 3);
    }

    private function assertSubject(User $actor, ShiftTask $task): void
    {
        $client = $task->task_scope === 'site' ? null : ($task->client ?? $task->shift->client);
        if ($client) {
            abort_unless((int) $client->site_id === (int) ($task->shift->site_id ?? $task->shift->client?->site_id), 403);
            Gate::forUser($actor)->authorize('view', $client);
        }
    }

    private function assertVersion(ShiftTask $task, int $version): void
    {
        if ((int) $task->version !== $version) {
            throw ValidationException::withMessages(['task' => 'This task changed. Refresh and check its latest state.']);
        }
    }
}
