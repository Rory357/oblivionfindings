<?php

namespace App\Http\Requests\Operations\Rostering;

use Illuminate\Foundation\Http\FormRequest;

class StoreRosterTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user && ($user->canDo('roster_templates.create') || $user->canDo('rostering.create'));
    }

    public function rules(): array
    {
        return self::templateRules();
    }

    public static function templateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template_type' => ['nullable', 'string', 'in:weekly,fortnightly,monthly'],
            'is_active' => ['nullable', 'boolean'],
            'template_shifts' => ['required', 'array', 'min:1'],
            'template_shifts.*.client_id' => ['required', 'integer', 'exists:clients,id'],
            'template_shifts.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'template_shifts.*.service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'template_shifts.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'template_shifts.*.start_time' => ['required', 'date_format:H:i'],
            'template_shifts.*.end_time' => ['required', 'date_format:H:i'],
            'template_shifts.*.shift_type' => ['nullable', 'string', 'in:standard,sleepover,on_call,split,travel'],
            'template_shifts.*.is_sleepover' => ['nullable', 'boolean'],
            'template_shifts.*.is_on_call' => ['nullable', 'boolean'],
            'template_shifts.*.is_lone_worker' => ['nullable', 'boolean'],
            'template_shifts.*.expected_break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'template_shifts.*.required_skills' => ['nullable', 'array'],
            'template_shifts.*.required_skills.*' => ['string', 'max:100'],
            'template_shifts.*.location' => ['nullable', 'string', 'max:255'],
            'template_shifts.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
