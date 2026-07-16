<?php

namespace App\Http\Requests\HealthSafety;

use App\Models\HsCorrectiveAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateHsCorrectiveActionFromRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recommendation_index' => ['required', 'integer', 'min:0'],
            'assigned_to_user_id' => ['required', 'integer'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'priority' => ['required', Rule::in([
                HsCorrectiveAction::PRIORITY_LOW,
                HsCorrectiveAction::PRIORITY_MEDIUM,
                HsCorrectiveAction::PRIORITY_HIGH,
                HsCorrectiveAction::PRIORITY_CRITICAL,
            ])],
            'responsibility_choice' => ['required', Rule::in(['transfer_task', 'new_responsibility'])],
            'source_control_room_task_id' => [
                'required_if:responsibility_choice,transfer_task',
                'nullable',
                'integer',
            ],
            'new_responsibility_reason' => [
                'required_if:responsibility_choice,new_responsibility',
                'nullable',
                'string',
                'min:10',
                'max:1000',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'assigned_to_user_id.required' => 'Choose the approved H&S staff member responsible for this action.',
            'due_date.required' => 'Set the date this corrective action is due.',
            'due_date.date_format' => 'Enter the due date as YYYY-MM-DD.',
            'responsibility_choice.required' => 'Choose whether to transfer an existing Control Room task or create a new responsibility.',
            'source_control_room_task_id.required_if' => 'Choose the Control Room task that will transfer to this action.',
            'new_responsibility_reason.required_if' => 'Explain why this recommendation needs a new responsibility.',
        ];
    }
}
