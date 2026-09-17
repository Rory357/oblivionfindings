<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use Illuminate\Foundation\Http\FormRequest;

class SaveItTicketMacroRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        $macro = $this->route('macro');

        return [
            ...$this->browserActorRules(),
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // Each action object passes through whole; ItTicketMacroService
            // enforces the per-type allowlist and required fields.
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*' => ['array'],
            'lock_version' => [...($macro ? ['required'] : ['sometimes']), 'integer', 'min:1'],
        ];
    }
}
