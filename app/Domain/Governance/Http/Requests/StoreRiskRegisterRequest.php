<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Services\RiskScoringService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RiskRegisterEntry::class);
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(array_keys(RiskScoringService::DEFAULT_APPETITE_THRESHOLDS))],
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'likelihood_score' => 'nullable|integer|min:1|max:5',
            'impact_score' => 'nullable|integer|min:1|max:5',
            'control_effectiveness' => 'nullable|in:none,weak,moderate,strong',
            'risk_owner_id' => 'nullable|exists:users,id',
            'mitigation_strategy' => 'nullable|in:treat,transfer,terminate,tolerate',
            'review_frequency' => 'nullable|in:monthly,quarterly,annual',
        ];
    }

    public function messages(): array
    {
        return self::plainMessages();
    }

    /** @return array<string, string> */
    public static function plainMessages(): array
    {
        return [
            'category.required' => 'Choose what kind of risk this is.',
            'category.in' => 'Choose one of the listed kinds of risk.',
            'title.required' => 'Give the risk a name.',
            'title.max' => 'Keep the name to 255 characters or fewer.',
            'description.required' => 'Describe what could happen and why.',
            'likelihood_score.min' => 'Choose a likelihood from 1 (rare) to 5 (almost certain).',
            'likelihood_score.max' => 'Choose a likelihood from 1 (rare) to 5 (almost certain).',
            'impact_score.min' => 'Choose an impact from 1 (insignificant) to 5 (catastrophic).',
            'impact_score.max' => 'Choose an impact from 1 (insignificant) to 5 (catastrophic).',
            'control_effectiveness.in' => 'Choose how well the current controls work from the list.',
            'risk_owner_id.exists' => 'Choose the risk owner from the list.',
            'mitigation_strategy.in' => 'Choose how you will respond to the risk from the list.',
            'review_frequency.in' => 'Choose how often the risk is reviewed from the list.',
        ];
    }
}
