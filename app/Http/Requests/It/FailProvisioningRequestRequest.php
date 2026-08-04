<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class FailProvisioningRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'failure_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
