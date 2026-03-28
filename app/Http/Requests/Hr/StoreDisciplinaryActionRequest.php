<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisciplinaryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.disciplinary.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_user_id'      => ['required', 'integer'],
            'action_type'           => ['required', 'string', 'in:verbal_warning,written_warning,final_warning,suspension,dismissal,other'],
            'allegation_summary'    => ['required', 'string', 'max:10000'],
            'investigation_notes'   => ['nullable', 'string', 'max:10000'],
            'investigator_user_id'  => ['nullable', 'integer'],
            'meeting_scheduled_at'  => ['nullable', 'date'],
            'meeting_location'      => ['nullable', 'string', 'max:255'],
            'support_person_advised' => ['boolean'],
            'response_deadline'     => ['nullable', 'date'],
            'good_faith_checklist'  => ['nullable', 'array'],
        ];
    }
}
