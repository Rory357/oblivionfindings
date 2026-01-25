<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('clients.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'site_id'    => ['nullable', 'integer', 'exists:sites,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'status'     => ['required', 'in:active,inactive'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'funding_type' => ['nullable', 'string', 'max:100'],
            'funding_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
