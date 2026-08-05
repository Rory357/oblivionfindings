<?php

namespace App\Http\Requests\HealthSafety;

use App\Models\HsCorrectiveAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHsCorrectiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in([
                HsCorrectiveAction::PRIORITY_LOW,
                HsCorrectiveAction::PRIORITY_MEDIUM,
                HsCorrectiveAction::PRIORITY_HIGH,
                HsCorrectiveAction::PRIORITY_CRITICAL,
            ])],
            'action_type' => ['nullable', Rule::in([
                HsCorrectiveAction::TYPE_CORRECTIVE,
                HsCorrectiveAction::TYPE_PREVENTIVE,
                HsCorrectiveAction::TYPE_IMPROVEMENT,
            ])],
            'assigned_to_user_id' => ['required', 'integer'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'assigned_to_user_id.required' => 'Choose the approved H&S staff member responsible for this action.',
            'due_date.required' => 'Set the date this corrective action is due.',
            'due_date.date_format' => 'Enter the due date as YYYY-MM-DD.',
        ];
    }
}
