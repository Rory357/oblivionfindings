<?php

namespace App\Http\Requests\It\Api;

use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Models\ItServiceIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransitionItApiWorkItemRequest extends FormRequest
{
    private const FIELDS = [
        'to', 'reason', 'waiting_party', 'next_action', 'resolution_code', 'resolution_summary',
    ];

    public function authorize(): bool
    {
        $identity = $this->attributes->get('it_service_identity');
        $ticketId = $this->route('workItem');
        if (! $identity instanceof ItServiceIdentity || ! is_numeric($ticketId)) {
            return false;
        }

        $ticket = app(ItApiWorkItemService::class)->authorizedTicket(
            $identity,
            (int) $ticketId,
            'work:transition',
            true,
        );
        abort_unless($ticket, 404);
        $this->attributes->set('it_api_ticket', $ticket);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', Rule::enum(ItWorkflowState::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'waiting_party' => ['nullable', Rule::in(['requester', 'vendor', 'approver', 'team', 'change', 'other'])],
            'next_action' => ['nullable', 'string', 'max:2000'],
            'resolution_code' => ['nullable', 'string', 'max:100'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add($field, 'This field is not available for API transitions.');
            }
        }];
    }
}
