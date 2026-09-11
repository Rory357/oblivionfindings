<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The requester rates how IT did (§K CSAT): 1–5 stars + an optional comment.
 * The controller first conceals an inaccessible ticket, then applies
 * ItTicketPolicy::csat for the own-ticket and resolved-state lifecycle rules.
 */
class SubmitCsatRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $ticket = $this->visibleTicketOrNotFound();

        return $this->hasCurrentBrowserActor() && (bool) $this->user()?->can('csat', $ticket);
    }

    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
