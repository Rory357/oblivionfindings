<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Http\Requests\It\Concerns\HasItDraftCommit;
use App\Models\ItAttachment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting on a ticket thread. Commenting follows the view audience
 * (authorized staff or participants). An INTERNAL note additionally requires
 * the canonical per-record work capability, including its Site and privacy
 * checks; a global permission or participant identity alone is insufficient.
 */
class StoreTicketCommentRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;
    use HasItDraftCommit;

    public function authorize(): bool
    {
        $this->visibleTicketOrNotFound();

        return $this->user() !== null && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return [
            ...$this->draftCommitRules(),
            'actor_user_id' => ['required_with:request_uuid', 'integer', 'min:1'],
            'request_uuid' => ['required_with:draft_uuid,expected_version,work_payload', 'uuid'],
            'expected_version' => ['required_with:request_uuid', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
            'work_payload' => ['sometimes', 'string', 'max:60000', 'json'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ItAttachment::uploadRules(),
        ];
    }
}
