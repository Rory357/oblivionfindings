<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Raise a sign-off request on a ticket (§P-S3). Authorised by
 * ItTicketPolicy@requestApproval — agent, category needs approval, none live.
 */
class RequestApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return (bool) ($ticket instanceof ItTicket && $this->user()?->can('requestApproval', $ticket));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
