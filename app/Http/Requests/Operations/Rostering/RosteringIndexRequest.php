<?php

namespace App\Http\Requests\Operations\Rostering;

use Illuminate\Foundation\Http\FormRequest;

class RosteringIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('rostering.viewAny');
    }

    public function rules(): array
    {
        return [
            'week' => ['nullable', 'date'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ];
    }
}
