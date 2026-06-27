<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('timesheets.manageAny')
            || $this->user()?->canDo('timesheets.approve');
    }

    public function rules(): array
    {
        return [
            'clock_in' => ['required', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'pay_type' => ['nullable', 'string', Rule::in(['standard', 'sleepover', 'on_call', 'public_holiday', 'night', 'weekend', 'evening'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_sleepover' => ['nullable', 'boolean'],
            'is_on_call' => ['nullable', 'boolean'],
            'is_public_holiday' => ['nullable', 'boolean'],
            'sleepover_disturbances' => ['nullable', 'array', 'max:50'],
            'sleepover_disturbances.*.start' => ['required_with:sleepover_disturbances', 'string', 'max:5'],
            'sleepover_disturbances.*.end' => ['required_with:sleepover_disturbances', 'string', 'max:5'],
            'sleepover_disturbances.*.minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'cost_centre' => ['nullable', 'string', 'max:50'],
            'project_code' => ['nullable', 'string', 'max:50'],
            'amendment_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
