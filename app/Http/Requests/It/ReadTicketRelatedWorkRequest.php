<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class ReadTicketRelatedWorkRequest extends FormRequest
{
    use BindsItBrowserActor, ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return ['actor_user_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:160'],
            'links_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'candidates_page' => ['sometimes', 'integer', 'min:1', 'max:100000']];
    }
}
