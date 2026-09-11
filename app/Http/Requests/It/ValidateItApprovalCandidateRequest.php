<?php

namespace App\Http\Requests\It;

use App\Domain\It\Data\ItTicketApprovalInput;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateItApprovalCandidateRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $ticket = $this->workableTicketOrNotFound();
        if (filter_var($this->input('approval_id'), FILTER_VALIDATE_INT) !== false && $this->integer('approval_id') > 0) {
            abort_unless($ticket->approvals()->whereKey($this->integer('approval_id'))->exists(), 404);
        }

        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return ['actor_user_id' => ['required', 'integer', 'min:1'], 'purpose' => ['required', Rule::in(['approval_work'])],
            'operation' => ['required', Rule::in(ItTicketApprovalInput::OPERATIONS)], 'approval_id' => ['present', 'nullable', 'integer', 'min:1'],
            'memory_uuid' => ['required', 'uuid'], 'candidate_uuid' => ['required', 'uuid'], 'base_ticket_version' => ['required', 'integer', 'min:1'],
            'step_index' => ['required', 'integer', 'between:0,3'], 'fields' => ['present', 'array', 'max:6'],
            'bound_scopes' => ['sometimes', 'array', 'list', 'max:100'],
            'bound_scopes.*' => ['array:primary_approver_user_id,cover_approver_user_id'],
            'pending_approval' => ['sometimes', 'nullable', 'array:actor_user_id,ticket_id,request_uuid,operation,approval_id,expected_version,fields']];
    }
}
