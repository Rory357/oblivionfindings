<?php

namespace App\Domain\Roadmap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInitiativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:roadmap_initiative_categories,id'],
            'category_key' => ['nullable', 'string', 'max:64'],
            'stream' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:32'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'sponsor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'next_decision' => ['nullable', 'string', 'max:64'],
            'decision_due_at' => ['nullable', 'date'],
            'target_fiscal_year' => ['nullable', 'integer', 'min:2000', 'max:3000'],
            'target_quarter' => ['nullable', 'integer', 'min:1', 'max:4'],
            'cost_estimate_low' => ['nullable', 'numeric', 'min:0'],
            'cost_estimate_high' => ['nullable', 'numeric', 'min:0'],
            'benefit_summary' => ['nullable', 'string'],
            'risk_summary' => ['nullable', 'string'],
            'dependency_summary' => ['nullable', 'string'],
            'impact_profile' => ['nullable', 'array'],
        ];
    }
}
