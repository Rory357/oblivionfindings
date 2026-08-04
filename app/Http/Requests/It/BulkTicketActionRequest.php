<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One bulk action over a selection of tickets (§F2): assign, set priority,
 * set a working status, or close. Resolving is deliberately absent — a
 * resolution requires a note (the resolve modal), and bulk must never
 * write an empty "what fixed it".
 */
class BulkTicketActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user && $user->canDo('it.manage'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['assign', 'priority', 'status', 'close'])],
            'assigned_to_user_id' => ['required_if:action,assign', 'nullable', 'integer', 'exists:users,id'],
            'priority' => ['required_if:action,priority', Rule::in(ItTicket::PRIORITIES)],
            // Working states only — settling happens via close (here) or the
            // resolve modal (with its required note), never a bare status set.
            'status' => ['required_if:action,status', Rule::in(ItTicket::OPEN_STATUSES)],
            'waiting_party' => ['required_if:status,waiting', 'nullable', Rule::in(['requester', 'vendor', 'approver', 'team', 'change', 'other'])],
            'waiting_reason' => ['required_if:status,waiting', 'nullable', 'string', 'max:1000'],
            'next_action' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required_if:action,close', 'nullable', 'string', 'max:1000'],
        ];
    }
}
