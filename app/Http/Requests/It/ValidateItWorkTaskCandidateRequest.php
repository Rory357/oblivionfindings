<?php

namespace App\Http\Requests\It;

use App\Domain\It\Data\ItWorkTaskInput;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItWorkTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateItWorkTaskCandidateRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $ticket = $this->workableTicketOrNotFound();
        if (filter_var($this->input('task_id'), FILTER_VALIDATE_INT) !== false && $this->integer('task_id') > 0) {
            abort_unless(ItWorkTask::query()->whereKey($this->integer('task_id'))->where('ticket_id', $ticket->id)->exists(), 404);
        }

        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'], 'purpose' => ['required', Rule::in(['task_work'])],
            'operation' => ['required', Rule::in(ItWorkTaskInput::OPERATIONS)],
            'task_id' => ['nullable', 'integer', 'min:1'], 'memory_uuid' => ['required', 'uuid'], 'candidate_uuid' => ['required', 'uuid'],
            'base_ticket_version' => ['required', 'integer', 'min:1'], 'step_index' => ['required', 'integer', 'between:0,3'],
            'fields' => ['present', 'array', 'max:20'], 'bound_scopes' => ['sometimes', 'array', 'list', 'max:100'],
            'bound_scopes.*' => ['array:team_id,assigned_to_user_id,dependency_ids,ordered_ids,approval_ids'],
            'pending_task' => ['sometimes', 'nullable', 'array:actor_user_id,ticket_id,request_uuid,operation,task_id,expected_version,fields'],
        ];
    }
}
