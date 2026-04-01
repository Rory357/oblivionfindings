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
            'title'                       => ['required', 'string', 'max:255'],
            'slug'                        => ['nullable', 'string', 'max:255'],
            'position_id'                 => ['nullable', 'integer', 'exists:hr_positions,id'],
            'department'                  => ['nullable', 'string', 'max:255'],
            'location'                    => ['nullable', 'string', 'max:255'],
            'employment_type'             => ['required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'is_remote'                   => ['boolean'],
            'is_internal'                 => ['boolean'],
            'summary'                     => ['nullable', 'string', 'max:1000'],
            'description'                 => ['required', 'string', 'max:50000'],
            'requirements'                => ['nullable', 'string', 'max:50000'],
            'responsibilities'            => ['nullable', 'string', 'max:50000'],
            'salary_range_min'            => ['nullable', 'numeric', 'min:0'],
            'salary_range_max'            => ['nullable', 'numeric', 'min:0', 'gte:salary_range_min'],
            'show_salary'                 => ['boolean'],
            'requires_approval'           => ['boolean'],
            'hiring_manager_id'           => ['nullable', 'integer', 'exists:users,id'],
            'notification_emails'         => ['nullable', 'array', 'max:20'],
            'notification_emails.*'       => ['email', 'max:255'],
            'screening_questions'         => ['nullable', 'array', 'max:15'],
            'screening_questions.*.id'    => ['required', 'string'],
            'screening_questions.*.question' => ['required', 'string', 'max:500'],
            'screening_questions.*.type'  => ['required', 'string', Rule::in(['yes_no', 'text', 'number', 'date', 'select'])],
            'screening_questions.*.required' => ['boolean'],
            'screening_questions.*.options' => ['nullable', 'array', 'max:20'],
            'screening_questions.*.options.*' => ['string', 'max:255'],
            'closes_at'                   => array_filter([
                'nullable', 'date',
                $this->isMethod('POST') ? 'after_or_equal:today' : null,
            ]),
        ];
    }

    public function messages(): array
    {
        return [
            'salary_range_max.gte' => 'The maximum salary must be greater than or equal to the minimum salary.',
            'screening_questions.max' => 'You can add a maximum of 15 screening questions.',
            'notification_emails.max' => 'You can add a maximum of 20 notification email addresses.',
        ];
    }
}
