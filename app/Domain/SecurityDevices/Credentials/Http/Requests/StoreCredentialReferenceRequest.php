<?php

namespace App\Domain\SecurityDevices\Credentials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCredentialReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('securityDevices.commands.admin') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'min:1', 'exists:sites,id'],
            'reference_key' => ['required', 'string', 'max:191', 'regex:/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/'],
            'provider' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z][a-zA-Z0-9._-]{1,63}$/'],
            'purpose' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z][a-zA-Z0-9._-]{1,63}$/'],
            'capabilities' => ['required', 'array', 'min:1', 'max:64'],
            'capabilities.*' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z][a-zA-Z0-9._:-]{1,119}$/'],
            'secret_manager_reference' => ['required', 'string', 'max:512', 'not_regex:/[\x00-\x1f\x7f]/'],
        ];
    }
}
