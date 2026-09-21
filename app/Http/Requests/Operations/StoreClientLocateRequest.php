<?php

namespace App\Http\Requests\Operations;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientLocateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'access_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'device_id' => ['missing'], 'client_id' => ['missing'], 'site_id' => ['missing'],
            'actor_id' => ['missing'], 'capability' => ['missing'], 'parameters' => ['missing'],
            'step_up_confirmed_at' => ['missing'], 'origin_context' => ['missing'],
            'audit_tail_event_id' => ['missing'],
            'break_glass' => ['missing'], 'it_change_id' => ['missing'], 'approved_by_user_id' => ['missing'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
