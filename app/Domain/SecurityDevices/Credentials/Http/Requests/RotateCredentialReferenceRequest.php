<?php

namespace App\Domain\SecurityDevices\Credentials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RotateCredentialReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('securityDevices.commands.admin') ?? false;
    }

    protected $dontFlash = [
        'secret_manager_reference',
        'credential_material',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'secret_manager_reference' => ['required_without:credential_material', 'nullable', 'string', 'max:512', 'not_regex:/[\x00-\x1f\x7f]/'],
            'credential_material' => ['nullable', 'array', 'min:1', 'max:64'],
            'credential_material.*' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
