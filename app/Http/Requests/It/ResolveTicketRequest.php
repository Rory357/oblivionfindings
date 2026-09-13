<?php

namespace App\Http\Requests\It;

use App\Domain\It\Data\ItTicketResolutionInput;
use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Http\Requests\It\Concerns\HasItDraftCommit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Resolving a ticket — the resolution note is REQUIRED and becomes the
 * final public reply, so "what fixed it" is always on the record.
 */
class ResolveTicketRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;
    use HasItDraftCommit;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return $this->hasCurrentBrowserActor() && (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return [
            ...$this->browserActorRules(),
            ...$this->draftCommitRules(),
            'expected_version' => ['required', 'integer', 'min:1'],
            ...ItTicketResolutionInput::rules(),
            'notify_requester' => ['sometimes', 'boolean'],
        ];
    }
}
