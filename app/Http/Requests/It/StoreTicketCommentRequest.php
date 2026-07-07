<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting on a ticket thread. Commenting follows the view audience
 * (agent or the requester); flagging a comment INTERNAL additionally
 * requires it.manage — a requester can never author (or fake) an
 * agent-only note.
 */
class StoreTicketCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ItTicket $ticket */
        $ticket = $this->route('ticket');
        $user = $this->user();

        if (! $user || ! $user->can('comment', $ticket)) {
            return false;
        }

        if ($this->boolean('is_internal') && ! $user->canDo('it.manage')) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
        ];
    }
}
