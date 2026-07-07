<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Resolving a ticket — the resolution note is REQUIRED and becomes the
 * final public reply, so "what fixed it" is always on the record.
 */
class ResolveTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ItTicket $ticket */
        $ticket = $this->route('ticket');
        $user = $this->user();

        return $user !== null && $user->can('resolve', $ticket);
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
            'notify_requester' => ['sometimes', 'boolean'],
        ];
    }
}
