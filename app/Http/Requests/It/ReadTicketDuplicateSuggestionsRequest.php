<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadTicketDuplicateSuggestionsRequest extends FormRequest
{
    use BindsItBrowserActor, ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        if ($this->route('ticket')) {
            $this->workableTicketOrNotFound();
        } elseif (! $this->user()?->can('create', ItTicket::class)) {
            return false;
        }

        return $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return ['actor_user_id' => ['required', 'integer', 'min:1'], 'query_uuid' => ['required', 'uuid'],
            ...($this->route('ticket') ? [] : [
                'title' => ['required', 'string', 'min:3', 'max:255'], 'site_id' => ['nullable', 'integer', 'min:1'],
                'is_organisation_wide' => ['sometimes', 'boolean'], 'it_service_id' => ['nullable', 'integer', 'min:1'],
                'work_type' => ['required', Rule::in(ItTicket::INTAKE_WORK_TYPES)],
            ])];
    }
}
