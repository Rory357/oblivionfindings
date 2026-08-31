<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItEmailDeliveryService;
use DomainException;
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
        $receivedAt = now()->toImmutable()->utc();

        return [
            'notification_id' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['delivered', 'failed', 'bounced'])],
            'provider_message_id' => ['nullable', 'string', 'max:255'],
            'occurred_at' => [
                'bail',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($receivedAt): void {
                    try {
                        ItEmailDeliveryService::resolveProviderEventAt($value, $receivedAt);
                    } catch (DomainException) {
                        $fail('The occurred at timestamp must be an absolute ISO 8601 timestamp no more than 300 seconds in the future.');
                    }
                },
            ],
            'error' => ['nullable', 'string', 'max:2000', 'required_if:status,failed,bounced'],
        ];
    }
}
