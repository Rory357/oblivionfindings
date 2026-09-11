<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItWorkTaskInput;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Non-persisting authorization proof for an exact task-scoped RAM candidate. */
final class ItWorkTaskCandidateService
{
    public function __construct(private readonly ItWorkTaskService $tasks, private readonly ItTicketRoutingEligibility $eligibility) {}

    public function validate(ItTicket $ticket, User $actor, array $input): array
    {
        return DB::transaction(function () use ($ticket, $actor, $input): array {
            [$ticket, $actor] = $this->tasks->lockContext($ticket, $actor, mutation: false);
            if ((int) $input['actor_user_id'] !== (int) $actor->id) {
                throw new AuthorizationException('Recover this work using its original account.');
            }
            $operation = $input['operation'];
            $taskId = isset($input['task_id']) ? (int) $input['task_id'] : null;
            $isTask = ! in_array($operation, ['create', 'reorder'], true);
            if ($isTask !== ($taskId !== null)) {
                throw ValidationException::withMessages(['task_id' => 'Keep this work with its original task operation.']);
            }
            $task = $taskId !== null ? ItWorkTask::query()->whereKey($taskId)->where('ticket_id', $ticket->id)->firstOrFail() : null;
            $version = (int) $input['base_ticket_version'];
            if ($version > (int) $ticket->lock_version) {
                throw ValidationException::withMessages(['base_ticket_version' => 'Keep the original reviewed version with this work.']);
            }
            $this->fields($operation, $input['fields']);
            $this->bindings($ticket, $task, $input['fields']);
            foreach ($input['bound_scopes'] ?? [] as $scope) {
                $this->bindings($ticket, $task, $scope);
            }
            if (isset($input['pending_task'])) {
                $pending = $input['pending_task'];
                Validator::make($pending, [
                    'actor_user_id' => ['required', 'integer', 'min:1'], 'ticket_id' => ['required', 'integer', 'min:1'],
                    'request_uuid' => ['required', 'uuid'], 'operation' => ['required', 'string'],
                    'task_id' => ['present', 'nullable', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'],
                    'fields' => ['present', 'array', 'max:20'],
                ])->validate();
                if ((int) $pending['actor_user_id'] !== (int) $actor->id || (int) $pending['ticket_id'] !== (int) $ticket->id
                    || $pending['operation'] !== $operation || (isset($pending['task_id']) ? (int) $pending['task_id'] : null) !== $taskId
                    || (int) $pending['expected_version'] > (int) $ticket->lock_version) {
                    throw ValidationException::withMessages(['pending_task' => 'The earlier command must keep its original actor, ticket, task and operation.']);
                }
                $this->fields($operation, $pending['fields']);
                $this->bindings($ticket, $task, $pending['fields']);
            }
            $blocker = match (true) {
                $ticket->isMerged() => ['code' => 'ticket_merged', 'message' => 'This work stays with its original ticket. Continue on the surviving ticket.'],
                ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true) => ['code' => 'ticket_settled', 'message' => 'Reopen this ticket before changing its tasks.'],
                $version !== (int) $ticket->lock_version => ['code' => 'ticket_changed', 'message' => 'Review the current ticket before applying this work.'],
                default => null,
            };

            return ['candidate' => [
                'kind' => 'memory', 'memory_uuid' => $input['memory_uuid'], 'candidate_uuid' => $input['candidate_uuid'],
                'actor_user_id' => (int) $actor->id, 'purpose' => 'task_work',
                'context_key' => 'ticket:'.$ticket->id.':task:'.($taskId ?? ($operation === 'reorder' ? 'order' : 'new')).':operation:'.$operation,
                'base_ticket_version' => $version, 'current_ticket_version' => (int) $ticket->lock_version,
                'authorized' => true, 'capabilities' => ['submit' => $blocker === null], 'blocker' => $blocker,
            ]];
        });
    }

    private function fields(string $operation, array $fields): void
    {
        $allowed = array_filter(array_keys(ItWorkTaskInput::rules($operation)), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($fields), $allowed) !== []) {
            throw ValidationException::withMessages(['fields' => 'This browser copy contains fields for another task operation.']);
        }
        ItWorkTaskInput::normalize($operation, $fields, partial: true);
    }

    private function bindings(ItTicket $ticket, ?ItWorkTask $task, array $fields): void
    {
        Validator::make($fields, [
            'team_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'approval_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'approval_ids' => ['sometimes', 'array', 'list', 'max:1000'], 'approval_ids.*' => ['integer', 'min:1', 'distinct'],
            'dependency_ids' => ['sometimes', 'array', 'list', 'max:1000'], 'dependency_ids.*' => ['integer', 'min:1', 'distinct'],
            'ordered_ids' => ['sometimes', 'array', 'list', 'max:1000'], 'ordered_ids.*' => ['integer', 'min:1', 'distinct'],
        ])->validate();
        if (isset($fields['team_id']) && (int) $fields['team_id'] !== (int) $task?->team_id
            && ! ItTeam::query()->whereKey($fields['team_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['fields.team_id' => 'This selected team is no longer available.']);
        }
        if (isset($fields['assigned_to_user_id']) && (int) $fields['assigned_to_user_id'] !== (int) $task?->assigned_to_user_id
            && $this->eligibility->agent((int) $fields['assigned_to_user_id'], $ticket, available: false) === null) {
            throw new AuthorizationException('A person selected in this browser copy is no longer available in this ticket scope.');
        }
        foreach (['dependency_ids', 'ordered_ids'] as $field) {
            $ids = array_map('intval', $fields[$field] ?? []);
            if ($ids !== [] && ItWorkTask::query()->where('ticket_id', $ticket->id)->whereIn('id', $ids)->count() !== count($ids)) {
                throw (new ModelNotFoundException)->setModel(ItWorkTask::class);
            }
        }
        $approvalIds = array_map('intval', $fields['approval_ids'] ?? []);
        if (isset($fields['approval_id'])) {
            $approvalIds[] = (int) $fields['approval_id'];
        }
        $approvalIds = array_values(array_unique($approvalIds));
        if ($approvalIds !== [] && ItTicketApproval::query()->where('it_ticket_id', $ticket->id)
            ->whereIn('id', $approvalIds)->count() !== count($approvalIds)) {
            throw (new ModelNotFoundException)->setModel(ItTicketApproval::class);
        }
    }
}
