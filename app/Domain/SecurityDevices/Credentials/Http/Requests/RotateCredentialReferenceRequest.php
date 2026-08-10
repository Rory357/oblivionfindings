<?php

namespace App\Domain\SecurityDevices\Credentials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RotateCredentialReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('securityDevices.commands.admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'secret_manager_reference' => ['required', 'string', 'max:512', 'not_regex:/[\x00-\x1f\x7f]/'],
        ];
    }
}
