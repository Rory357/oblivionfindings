<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class BulkTimesheetActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.time.manage')
            || $this->user()?->canDo('hr.time.approveTeam');
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:hr_timesheets,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
