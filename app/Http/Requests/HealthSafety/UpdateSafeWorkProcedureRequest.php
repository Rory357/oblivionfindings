<?php

namespace App\Http\Requests\HealthSafety;

class UpdateSafeWorkProcedureRequest extends StoreSafeWorkProcedureRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('procedures.manage');
    }

    public function rules(): array
    {
        return [...parent::rules(), 'applicable_roles' => ['sometimes', 'nullable', 'array'], 'applicable_sites' => ['sometimes', 'nullable', 'array'], 'change_summary' => ['required', 'string', 'max:5000'], 'expected_version' => ['required', 'integer', 'min:1'], 'expected_lock_version' => ['required', 'integer', 'min:1']];
    }
}
