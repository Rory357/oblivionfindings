<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class ApproveProvisioningRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
