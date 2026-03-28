<?php

namespace App\Domain\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRiskRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('risk'));
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'likelihood_score' => 'sometimes|integer|min:1|max:5',
            'impact_score' => 'sometimes|integer|min:1|max:5',
            'control_effectiveness' => 'sometimes|in:none,weak,moderate,strong',
            'risk_owner_id' => 'sometimes|exists:users,id',
            'mitigation_strategy' => 'sometimes|in:treat,transfer,terminate,tolerate',
        ];
    }
}
