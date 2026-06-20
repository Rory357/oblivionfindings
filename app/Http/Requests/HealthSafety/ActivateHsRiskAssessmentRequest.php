<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for "Approve & activate" — an optional approver note is persisted to
 * approval_note. The draft → active transition (and review-date scheduling) is in
 * the service; a non-draft is caught in the controller.
 */
class ActivateHsRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approver_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
