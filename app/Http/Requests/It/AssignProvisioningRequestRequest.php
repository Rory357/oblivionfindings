<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class AssignProvisioningRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
