<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordHsWorksafeDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hazards.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'notifiable' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'source' => ['nullable', Rule::in(['manual', 'incident_report', 'classifier'])],
        ];
    }
}
