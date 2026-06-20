<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a worker consultation. ONE canonical NZ consultation-type set (the FE
 * TilePicker mirrors these seven). A supporting document can be attached at
 * create time — the wizard's Documents step posts it here (forceFormData), so we
 * accept an optional file rather than forcing a second round-trip to the
 * documents endpoint.
 */
class StoreConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hazards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'consultation_type' => ['required', 'string', 'in:hazard_review,risk_assessment,procedure_change,policy_review,equipment_change,change_notification,general'],
            'description' => ['required', 'string', 'max:5000'],
            'site_id' => ['required', 'exists:sites,id'],
            'consultation_date' => ['required', 'date'],
            'workers_consulted' => ['nullable', 'array'],
            'workers_consulted.*' => ['integer', 'exists:users,id'],
            'document' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,xlsx,jpg,png'],
        ];
    }
}
