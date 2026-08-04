<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class CancelProvisioningRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
