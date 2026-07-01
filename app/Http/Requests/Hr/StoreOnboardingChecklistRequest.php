<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start-onboarding wizard. Supports two converged modes:
 *  - hire_mode=existing → onboard an existing employee profile.
 *  - hire_mode=new      → create the person (via EmployeeIntakeService) and
 *                         onboard them in one path.
 */
class StoreOnboardingChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.onboarding.manage') ?? false;
    }

    /** Default the hire mode so legacy existing-employee callers stay valid. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('hire_mode')) {
            $this->merge(['hire_mode' => 'existing']);
        }
    }

    public function rules(): array
    {
        $isNew = $this->input('hire_mode') === 'new';

        return [
            'hire_mode' => ['required', Rule::in(['existing', 'new'])],

            // Existing-employee branch
            'employee_profile_id' => [
                Rule::requiredIf(! $isNew),
                'nullable',
                'integer',
                'exists:hr_employee_profiles,id',
            ],

            // New-hire branch (shared with Add Employee)
            'name' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:255'],
            'email' => [Rule::requiredIf($isNew), 'nullable', 'email', 'max:255'],
            'position_title' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', 'string', 'max:50'],
            'primary_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],

            // Checklist options (both branches)
            'template_id' => ['nullable', 'integer', 'exists:hr_onboarding_templates,id'],
            'assign_compliance' => ['sometimes', 'boolean'],
            'send_welcome_email' => ['sometimes', 'boolean'],
            'welcome_email_id' => [
                'nullable',
                'integer',
                'exists:hr_onboarding_emails,id',
                'required_if:send_welcome_email,true',
            ],
        ];
    }
}
