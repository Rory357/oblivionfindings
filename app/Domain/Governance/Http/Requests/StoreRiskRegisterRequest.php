<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Models\RiskRegisterEntry;
use Illuminate\Foundation\Http\FormRequest;

class StoreRiskRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RiskRegisterEntry::class);
    }

    public function rules(): array
    {
        return [
            'category' => 'required|string',
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
}
