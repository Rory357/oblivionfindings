<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class ValidateItMergeCandidateRequest extends FormRequest
{
    use BindsItBrowserActor, ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return $this->user()?->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return ['actor_user_id' => ['required', 'integer', 'min:1'], 'purpose' => ['required', 'in:merge_work'],
            'ticket_id' => ['required', 'integer', 'min:1'], 'memory_uuid' => ['required', 'uuid'], 'candidate_uuid' => ['required', 'uuid'],
            'base_ticket_version' => ['required', 'integer', 'min:1'], 'step_index' => ['required', 'integer', 'between:0,1'],
            'fields' => ['present', 'array:reason,target_ticket_id,target_version'], 'fields.reason' => ['nullable', 'string', 'max:5000'],
            'fields.target_ticket_id' => ['nullable', 'integer', 'min:1'], 'fields.target_version' => ['nullable', 'integer', 'min:1'],
            'bound_scopes' => ['sometimes', 'array', 'list', 'max:100'], 'bound_scopes.*' => ['array:target_ticket_id'],
            'bound_scopes.*.target_ticket_id' => ['nullable', 'integer', 'min:1']];
    }
}
