<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmTicketResolutionRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $ticket = $this->visibleTicketOrNotFound();

        return $this->hasCurrentBrowserActor() && (bool) $this->user()?->can('confirmResolution', $ticket);
    }

    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
