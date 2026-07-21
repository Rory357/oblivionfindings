<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The requester rates how IT did (§K CSAT): 1–5 stars + an optional comment.
 * The controller first conceals an inaccessible ticket, then applies
 * ItTicketPolicy::csat for the own-ticket and resolved-state lifecycle rules.
 */
class SubmitCsatRequest extends FormRequest
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
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
