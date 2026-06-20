<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for "Record review / residual" — re-score the residual risk after
 * controls, set acceptability, and capture an optional review note (last_review_note).
 */
class UpdateResidualRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'residual_likelihood' => ['required', 'integer', 'between:1,5'],
            'residual_consequence' => ['required', 'integer', 'between:1,5'],
            'risk_acceptable' => ['nullable', 'boolean'],
            'review_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
