<?php

namespace App\Http\Requests\HealthSafety;

use App\Services\HealthSafety\SafeWorkProcedureService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSafeWorkProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ($this->user()->canDo('procedures.create') || $this->user()->canDo('procedures.manage'));
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'reference_number' => ['required', 'string', 'max:100'], 'category' => ['required', Rule::in(SafeWorkProcedureService::CATEGORIES)], 'purpose' => ['nullable', 'string', 'max:10000'], 'scope' => ['nullable', 'string', 'max:10000'], 'steps' => ['nullable', 'array'], 'steps.*.description' => ['required', 'string', 'max:5000'], 'steps.*.safety_notes' => ['nullable', 'string', 'max:5000'], 'ppe_required' => ['nullable', 'array'], 'ppe_required.*' => ['string', 'max:255'], 'hazards_addressed' => ['nullable', 'array'], 'hazards_addressed.*' => ['string', 'max:255'], 'emergency_procedures' => ['nullable', function ($attribute, $value, $fail) {
            if (! is_string($value) && (! is_array($value) || collect($value)->contains(fn ($line) => ! is_string($line)))) {
                $fail('Emergency instructions must be text or a list of text lines.');
            }
        }], 'applicable_roles' => ['present', 'array'], 'applicable_roles.*' => ['string', 'max:100'], 'applicable_sites' => ['present', 'array'], 'applicable_sites.*' => ['integer', 'min:1'], 'related_training' => ['sometimes', 'nullable', 'array'], 'related_training.*' => ['integer', 'min:1'], 'review_date' => ['nullable', 'date_format:Y-m-d'], 'review_frequency_months' => ['nullable', 'integer', 'between:1,120'], 'owner_id' => ['nullable', 'integer', 'min:1']];
    }
}
