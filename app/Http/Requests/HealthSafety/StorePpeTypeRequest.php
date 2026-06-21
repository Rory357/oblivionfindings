<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create / update a PPE catalogue type. Shared by storeType + updateType so the
 * ruleset can't drift. Category + inspection-frequency enums mirror the DB column
 * domains and the wizard's TilePicker / Segmented options exactly.
 */
class StorePpeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hazards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:head,eye,ear,respiratory,hand,foot,body,fall_protection,high_visibility,other'],
            'description' => ['nullable', 'string', 'max:2000'],
            'hazards_addressed' => ['nullable', 'string', 'max:2000'],
            'standards_reference' => ['nullable', 'string', 'max:255'],
            'inspection_frequency' => ['nullable', 'string', 'in:daily,weekly,monthly,quarterly,annually'],
            'typical_lifespan_months' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }
}
