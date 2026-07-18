<?php

namespace App\Http\Requests\It;

use App\Domain\It\Enums\ItWorkflowState;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionItWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ItTicket $ticket */
        $ticket = $this->route('ticket');
        $user = $this->user();

        return $user !== null && $user->can('update', $ticket);
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
