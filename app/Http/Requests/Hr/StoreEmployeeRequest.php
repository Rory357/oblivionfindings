<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.employees.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'email'           => ['required', 'email', 'max:255', 'unique:users,email'],
            'preferred_name'  => ['nullable', 'string', 'max:255'],
            'role'            => ['nullable', 'string', 'exists:roles,name'],
            'position_id'     => ['nullable', 'integer', 'exists:hr_positions,id'],
            'position_title'  => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'department'      => ['nullable', 'string', 'max:255'],
            'primary_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date'      => ['nullable', 'date'],
            'work_phone'      => ['nullable', 'string', 'max:50'],
        ];
    }
}
