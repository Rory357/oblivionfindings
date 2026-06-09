<?php

namespace App\Http\Requests\Operations\Rostering;

use Illuminate\Foundation\Http\FormRequest;

class ApplyRosterTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user && ($user->canDo('roster_templates.update') || $user->canDo('rostering.edit'));
    }

    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date'],
            // How many cadence cycles to stamp. 1 = the chosen week only (the
            // historical behaviour). The interval per cycle is derived from the
            // template's cadence (weekly/fortnightly/monthly) in the controller.
            'cycles' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'confirm_warnings' => ['sometimes', 'boolean'],
        ];
    }
}
