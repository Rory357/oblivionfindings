<?php

namespace App\Http\Requests\Operations\Rostering;

use Illuminate\Foundation\Http\FormRequest;

class AutoScheduleRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('rostering.autoSchedule');
    }

    public function rules(): array
    {
        return [
            'week' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ];
    }
}
