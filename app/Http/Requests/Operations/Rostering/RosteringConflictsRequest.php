<?php

namespace App\Http\Requests\Operations\Rostering;

use Illuminate\Foundation\Http\FormRequest;

class RosteringConflictsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('rostering.viewAny');
    }

    public function rules(): array
    {
        return [
            'week' => ['nullable', 'date'],
        ];
    }
}
