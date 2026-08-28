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
            // The command service resolves target and Site ownership together;
            // request-layer exists rules would disclose which IDs are real.
            'target_user_id' => ['required', 'integer'],
            'clock_in' => ['required', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'shift_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'pay_type' => ['nullable', 'string', Rule::in(['standard', 'sleepover', 'on_call', 'public_holiday', 'night', 'weekend', 'evening'])],
            'is_sleepover' => ['nullable', 'boolean'],
            'is_on_call' => ['nullable', 'boolean'],
            'is_public_holiday' => ['nullable', 'boolean'],
            'sleepover_disturbances' => ['nullable', 'array', 'max:50'],
            'sleepover_disturbances.*.start' => ['required_with:sleepover_disturbances', 'string', 'max:5'],
            'sleepover_disturbances.*.end' => ['required_with:sleepover_disturbances', 'string', 'max:5'],
            'sleepover_disturbances.*.minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            // The rebuilt "Clock on behalf" wizard requires a reason — it is
            // persisted to the entry notes + an audit amendment row by the service.
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
