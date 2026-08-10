<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class FulfilProvisioningRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'external_ref' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence_summary' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
