<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItAttachment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting on a ticket thread. Commenting follows the view audience
 * (agent or the requester); flagging a comment INTERNAL additionally
 * requires it.manage — a requester can never author (or fake) an
 * agent-only note.
 */
class StoreTicketCommentRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->visibleTicketOrNotFound();

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:'.ItAttachment::MAX_SIZE_KB,
                'mimes:'.ItAttachment::ALLOWED_MIMES,
            ],
        ];
    }
}
