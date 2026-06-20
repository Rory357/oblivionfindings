<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a PPE inventory item. Mirrors the Add-inventory wizard's per-step
 * validation. Optional documents (certificate / declaration of conformity) may be
 * captured at create time via the Documents step (forceFormData), so we accept an
 * optional file array rather than forcing a second round-trip.
 */
class StorePpeInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hazards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'ppe_type_id' => ['required', 'exists:ppe_types,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'condition' => ['sometimes', 'string', 'in:new,good,fair,poor,condemned'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'next_inspection_due' => ['nullable', 'date'],
            'documents' => ['nullable', 'array'],
            'documents.*.file' => ['required', 'file', 'max:20480'],
            'documents.*.kind' => ['nullable', 'string', 'max:30'],
            'documents.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
