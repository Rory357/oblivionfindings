<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The requester rates how IT did (§K CSAT): 1–5 stars + an optional comment.
 * Authorisation (own ticket, still resolved = editable until closed) lives in
 * ItTicketPolicy::csat — so a stranger, an agent, or a closed ticket 403s
 * before validation ever runs.
 */
class SubmitCsatRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ItTicket $ticket */
        $ticket = $this->route('ticket');
        $user = $this->user();

        return $user !== null && $user->can('csat', $ticket);
    }

    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
