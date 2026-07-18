<?php

namespace App\Http\Requests\It;

use App\Models\ItMajorIncident;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItMajorIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', Rule::in(ItTicket::CATEGORIES)], 'priority' => ['required', Rule::in(ItTicket::PRIORITIES)],
            'severity' => ['required', Rule::in(ItMajorIncident::SEVERITIES)], 'impact_summary' => ['required', 'string', 'max:10000'],
            'communications_lead_user_id' => ['nullable', 'integer', 'exists:users,id'], 'target_update_minutes' => ['required', 'integer', 'between:5,240'],
            'service_ids' => ['sometimes', 'array'], 'service_ids.*' => ['integer', 'distinct', 'exists:it_services,id'],
            'site_ids' => ['sometimes', 'array'], 'site_ids.*' => ['integer', 'distinct', 'exists:sites,id'],
            'incident_ids' => ['sometimes', 'array'], 'incident_ids.*' => ['integer', 'distinct', 'exists:it_tickets,id'],
            'control_room_alert_id' => ['nullable', 'integer', 'exists:control_room_alerts,id'],
        ];
    }
}
