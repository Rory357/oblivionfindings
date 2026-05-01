<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('timesheets.manageAny') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'clock_in' => ['required', 'date'],
            'clock_out' => ['required', 'date', 'after:clock_in'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'notes' => ['nullable', 'string', 'max:500'],
            'project_code' => ['nullable', 'string', 'max:50'],
            'cost_centre' => ['nullable', 'string', 'max:50'],
        ];
    }
}
