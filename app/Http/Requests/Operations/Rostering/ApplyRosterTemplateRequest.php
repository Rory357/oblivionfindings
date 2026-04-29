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
            'confirm_warnings' => ['sometimes', 'boolean'],
        ];
    }
}
