<?php

namespace App\Http\Requests\It;

use App\Domain\It\Enums\ItWorkflowState;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionItWorkRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return [
            'workflow_state' => ['required', Rule::enum(ItWorkflowState::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'waiting_party' => ['nullable', Rule::in(['requester', 'vendor', 'approver', 'team', 'change', 'other'])],
            'next_action' => ['nullable', 'string', 'max:2000'],
            'resolution_code' => ['nullable', 'string', 'max:100'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
