<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Schedule a committee meeting. Attendees default to the committee members when
 * omitted (handled in the controller).
 */
class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hazards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date'],
            'scheduled_timezone' => ['nullable', 'string', 'max:80'],
            'scheduled_offset' => ['nullable', 'string', 'max:6'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda_items' => ['nullable', 'array'],
            'agenda_items.*' => ['string', 'max:255'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['integer', 'exists:users,id'],
        ];
    }
}
