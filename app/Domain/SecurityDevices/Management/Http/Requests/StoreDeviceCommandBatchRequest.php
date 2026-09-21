<?php

namespace App\Domain\SecurityDevices\Management\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceCommandBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'audit_tail_event_id' => ['missing'],
            'origin_context' => ['missing'],
            'origin' => ['missing'],
            'client_id' => ['missing'],
            'assignment_id' => ['missing'],
            'consent_id' => ['missing'],
            'access_fingerprint' => ['missing'],
            'workspace' => ['required', 'string', 'max:40'],
            'device_ids' => ['required', 'array', 'min:2', 'max:100'],
            'device_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'capability' => ['required', 'string', 'max:120'],
            'parameters' => ['present', 'array'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'it_change_ids' => ['sometimes', 'array', 'max:100'],
            'it_change_ids.*' => ['required', 'integer', 'min:1'],
            'impact_acknowledged' => ['sometimes', 'boolean'],
            'confirmation_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
