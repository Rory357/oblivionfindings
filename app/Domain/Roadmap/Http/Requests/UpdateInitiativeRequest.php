<?php

namespace App\Domain\Roadmap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInitiativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'max:32'],
            'stream' => ['sometimes', 'string', 'max:32'],
            'owner_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'sponsor_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'next_decision' => ['sometimes', 'nullable', 'string', 'max:64'],
            'decision_due_at' => ['sometimes', 'nullable', 'date'],
            'target_fiscal_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:3000'],
            'target_quarter' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4'],
            'cost_estimate_low' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_estimate_high' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'benefit_summary' => ['sometimes', 'nullable', 'string'],
            'risk_summary' => ['sometimes', 'nullable', 'string'],
            'dependency_summary' => ['sometimes', 'nullable', 'string'],
            'impact_profile' => ['sometimes', 'nullable', 'array'],
            'manual_priority_override' => ['sometimes', 'boolean'],
            'manual_priority_reason' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
