<?php

namespace App\Domain\SecurityDevices\AccessControl\Http\Requests;

use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessControlCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('securityDevices.accessControl.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'min:1'],
            'access_schedule_id' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:120'],
            'holder_type' => ['required', Rule::in(AccessControlCredential::VALID_HOLDER_TYPES)],
            'holder_id' => ['required', 'integer', 'min:1'],
            'reference_key' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/-]{1,158}$/',
            ],
            'device_ids' => ['required', 'array', 'min:1', 'max:100'],
            'device_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ];
    }
}
