<?php

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Services\LeaveService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.leave.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id'         => ['nullable', 'integer', 'exists:users,id'],
            'leave_type'      => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'period'          => ['nullable', Rule::in(['full_day', 'half_day_am', 'half_day_pm'])],
            'starts_at'       => ['required', 'date', 'after_or_equal:today'],
            'ends_at'         => ['required', 'date', 'after_or_equal:starts_at'],
            'hours_requested' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'reason'          => ['nullable', 'string', 'max:2000'],
            'supporting_doc'  => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $period = $this->input('period');
            if (in_array($period, ['half_day_am', 'half_day_pm'], true)
                && $this->filled('starts_at') && $this->filled('ends_at')
                && $this->date('starts_at')?->toDateString() !== $this->date('ends_at')?->toDateString()) {
                $validator->errors()->add('period', 'A half-day can only be requested for a single day.');
            }
        });
    }
}
