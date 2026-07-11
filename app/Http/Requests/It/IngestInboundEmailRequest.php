<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class IngestInboundEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $secret = (string) config('it.inbound_mail.secret', '');

        return $secret !== ''
            && hash_equals($secret, (string) $this->header('X-IT-Inbound-Secret'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable', 'string'],
            'message_id' => ['nullable', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:255'],
        ];
    }
}
