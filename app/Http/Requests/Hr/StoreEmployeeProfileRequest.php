<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.employees.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_number'    => ['nullable', 'string', 'max:50'],
            'date_of_birth'      => ['nullable', 'date', 'before:today'],
            'gender'             => ['nullable', 'string', 'max:50'],
            'ethnicity'          => ['nullable', 'string', 'max:100'],
            'personal_email'     => ['nullable', 'email', 'max:255'],
            'personal_phone'     => ['nullable', 'string', 'max:50'],
            'home_address'       => ['nullable', 'string', 'max:1000'],
            'work_email'         => ['nullable', 'email', 'max:255'],
            'work_phone'         => ['nullable', 'string', 'max:50'],
            'position_title'     => ['required', 'string', 'max:255'],
            'position_role'      => ['nullable', 'string', 'max:100'],
            'employment_type'    => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'contract_type'      => ['nullable', 'string', Rule::in(['permanent', 'fixed_term', 'casual', 'contractor'])],
            'hours_per_week'     => ['nullable', 'numeric', 'min:0', 'max:60'],
            'hourly_rate'        => ['nullable', 'numeric', 'min:0'],
            'annual_salary'      => ['nullable', 'numeric', 'min:0'],
            'pay_frequency'      => ['nullable', 'string', Rule::in(['weekly', 'fortnightly', 'monthly'])],
            'start_date'         => ['nullable', 'date'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:start_date'],
            'probation_end_date' => ['nullable', 'date'],
            'termination_reason' => ['nullable', 'string', 'max:1000'],
            'is_active'          => ['sometimes', 'boolean'],
            'primary_site_id'    => ['nullable', 'integer', 'exists:sites,id'],
            'secondary_site_ids' => ['nullable', 'array'],
            'secondary_site_ids.*' => ['integer', 'exists:sites,id'],
            'emergency_contacts' => ['nullable', 'array'],
            'bank_account'       => ['nullable', 'string', 'max:255'],
            'ird_number'         => ['nullable', 'string', 'max:20'],
            'tax_code'           => ['nullable', 'string', 'max:10'],
            'kiwisaver_rate'     => ['nullable', 'numeric', 'min:0', 'max:10'],
            'can_drive_clients'  => ['sometimes', 'boolean'],
            'is_first_aider'     => ['sometimes', 'boolean'],
            'is_fire_warden'     => ['sometimes', 'boolean'],
            'notes'              => ['nullable', 'string', 'max:5000'],
            'restricted_notes'   => ['nullable', 'string', 'max:5000'],
        ];
    }
}
