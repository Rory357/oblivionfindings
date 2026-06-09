<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClockOnBehalfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('timesheets.manageAny')
            || $this->user()?->canDo('timesheets.approve');
    }

    public function rules(): array
    {
        return [
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'clock_in' => ['required', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'pay_type' => ['nullable', 'string', Rule::in(['standard', 'sleepover', 'on_call', 'public_holiday', 'night', 'weekend', 'evening'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
