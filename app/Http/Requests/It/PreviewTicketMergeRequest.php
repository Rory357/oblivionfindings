<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class PreviewTicketMergeRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableMergeParentsOrNotFound();

        return $this->user()?->canDo('it.manage')
            && $this->integer('actor_user_id') === (int) $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'target_ticket_id' => ['required', 'integer', 'min:1'],
            'source_version' => ['required', 'integer', 'min:1'],
            'target_version' => ['required', 'integer', 'min:1'],
            'review_nonce' => ['required', 'uuid'],
        ];
    }
}
