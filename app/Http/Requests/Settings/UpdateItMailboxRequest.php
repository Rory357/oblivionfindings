<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItMailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('integrations.manage_secrets') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'connection_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'mailbox_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
