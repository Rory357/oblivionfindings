<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.recruitment.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'position_id'      => ['nullable', 'integer', 'exists:hr_positions,id'],
            'department'       => ['nullable', 'string', 'max:255'],
            'location'         => ['nullable', 'string', 'max:255'],
            'employment_type'  => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'description'      => ['required', 'string', 'max:50000'],
            'requirements'     => ['nullable', 'string', 'max:50000'],
            'salary_range_min' => ['nullable', 'numeric', 'min:0'],
            'salary_range_max' => ['nullable', 'numeric', 'min:0'],
            'show_salary'      => ['boolean'],
            'closes_at'        => array_filter([
                'nullable', 'date',
                $this->isMethod('POST') ? 'after_or_equal:today' : null,
            ]),
        ];
    }
}
