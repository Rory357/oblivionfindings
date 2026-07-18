<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItEmailDeliveryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = (string) config('it.outbound_mail.status_secret');
        $provided = (string) $this->header('X-IT-Delivery-Secret');

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'notification_id' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['delivered', 'failed', 'bounced'])],
            'provider_message_id' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'error' => ['nullable', 'string', 'max:2000', 'required_if:status,failed,bounced'],
        ];
    }
}
