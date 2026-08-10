<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit and/or reassign a single onboarding task.
 */
class UpdateOnboardingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.onboarding.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', 'max:100'],
            'due_date' => ['nullable', 'date'],
            'is_required' => ['sometimes', 'boolean'],
            'sign_off_required' => ['sometimes', 'boolean'],
            'assigned_to_user_id' => ['nullable', 'integer'],
            'assigned_to_role' => ['nullable', 'string', 'max:100'],
        ];
    }
}
