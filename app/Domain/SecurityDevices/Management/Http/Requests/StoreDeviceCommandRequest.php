<?php

namespace App\Domain\SecurityDevices\Management\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capability' => ['required', 'string', 'max:120'],
            'parameters' => ['present', 'array'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'it_change_id' => ['nullable', 'integer', 'min:1'],
            'break_glass' => ['sometimes', 'boolean'],
            'break_glass_reason' => ['nullable', 'string', 'min:20', 'max:1000'],
            'break_glass_reviewer_user_id' => ['nullable', 'integer', 'min:1'],
            'impact_acknowledged' => ['sometimes', 'boolean'],
            'confirmation_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
