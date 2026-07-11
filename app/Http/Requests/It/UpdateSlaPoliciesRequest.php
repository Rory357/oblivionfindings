<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The SLA target grid: one pair of minutes per priority, all four required
 * (§N7 — the editor writes the whole grid, tenant rows override the §G
 * defaults). Admin-only on top of it.manage: retuning the helpdesk's
 * promises is an over-the-queue decision.
 */
class UpdateSlaPoliciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user && $user->canDo('it.manage') && $user->hasRole('admin'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (ItTicket::PRIORITIES as $priority) {
            // 5 minutes to 30 days for first response; up to 90 days to
            // resolve — wide enough for any sane policy, tight enough to
            // catch a fat-fingered "0" or an hours-vs-minutes mix-up.
            $rules["{$priority}.first_response_minutes"] = ['required', 'integer', 'min:5', 'max:43200'];
            $rules["{$priority}.resolution_minutes"] = [
                'required', 'integer', 'min:5', 'max:129600',
                "gte:{$priority}.first_response_minutes",
            ];
        }

        // Optional tenant-wide business-hours calendar (v1: a single daily
        // window across the chosen working days). Off → the 24/7 clock,
        // unchanged. The controller applies it to every priority row.
        $rules['business_hours_enabled'] = ['boolean'];
        $rules['open_time'] = ['required_if:business_hours_enabled,true', 'nullable', 'date_format:H:i'];
        $rules['close_time'] = ['required_if:business_hours_enabled,true', 'nullable', 'date_format:H:i'];
        $rules['working_days'] = ['required_if:business_hours_enabled,true', 'nullable', 'array', 'min:1'];
        $rules['working_days.*'] = ['in:mon,tue,wed,thu,fri,sat,sun'];
        $rules['holiday_dates'] = ['nullable', 'array', 'max:60'];
        $rules['holiday_dates.*'] = ['date_format:Y-m-d'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->boolean('business_hours_enabled')
                && $this->filled('open_time') && $this->filled('close_time')
                && $this->input('close_time') <= $this->input('open_time')) {
                $validator->errors()->add('close_time', 'The close time must be after the open time.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach (ItTicket::PRIORITIES as $priority) {
            $attributes["{$priority}.first_response_minutes"] = "{$priority} first-response target";
            $attributes["{$priority}.resolution_minutes"] = "{$priority} resolution target";
        }

        return $attributes;
    }
}
