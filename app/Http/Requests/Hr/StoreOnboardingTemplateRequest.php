<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update an onboarding template (reusable task set).
 */
class StoreOnboardingTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.onboarding.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['nullable', 'integer', 'exists:hr_onboarding_templates,id'],
            'role' => ['required', 'string', 'max:100'],
            'site_type' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.category' => ['required', 'string', 'max:100'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string', 'max:2000'],
            'tasks.*.is_required' => ['required', 'boolean'],
            'tasks.*.sort_order' => ['nullable', 'integer', 'min:1'],
            'tasks.*.assigned_to_role' => ['nullable', 'string', 'max:100'],
            'tasks.*.sign_off_required' => ['sometimes', 'boolean'],
            // Optional training course to auto-enrol into (induction tasks).
            'tasks.*.course_code' => ['nullable', 'string', 'max:100'],
        ];
    }
}
