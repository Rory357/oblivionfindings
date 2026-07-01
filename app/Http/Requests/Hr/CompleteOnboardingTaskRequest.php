<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Complete an onboarding task, optionally attaching a multipart evidence file,
 * a note, and a sign-off user.
 */
class CompleteOnboardingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.onboarding.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'signed_off_by' => ['nullable', 'integer', 'exists:users,id'],
            'evidence' => [
                'nullable',
                'file',
                'max:20480', // 20 MB
                'mimes:pdf,jpg,jpeg,png,doc,docx',
            ],
        ];
    }
}
