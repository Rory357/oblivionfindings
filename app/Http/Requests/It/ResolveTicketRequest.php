<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Resolving a ticket — the resolution note is REQUIRED and becomes the
 * final public reply, so "what fixed it" is always on the record.
 */
class ResolveTicketRequest extends FormRequest
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
            'note' => ['required', 'string', 'max:5000'],
            'notify_requester' => ['sometimes', 'boolean'],
        ];
    }
}
