<?php

namespace App\Http\Requests\It;

use App\Models\ItMajorIncident;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItMajorIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category' => ['sometimes', Rule::in(ItTicket::CATEGORIES)], 'priority' => ['sometimes', Rule::in(ItTicket::PRIORITIES)],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:2000'], 'severity' => ['sometimes', Rule::in(ItMajorIncident::SEVERITIES)],
            'impact_summary' => ['sometimes', 'nullable', 'string', 'max:10000'], 'commander_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'communications_lead_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'], 'target_update_minutes' => ['sometimes', 'integer', 'between:5,240'],
            'restoration_summary' => ['sometimes', 'nullable', 'string', 'max:20000'], 'root_cause_summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'review_summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'service_ids' => ['sometimes', 'array'], 'service_ids.*' => ['integer', 'distinct', 'exists:it_services,id'],
            'site_ids' => ['sometimes', 'array'], 'site_ids.*' => ['integer', 'distinct', 'exists:sites,id'],
            'incident_ids' => ['sometimes', 'array'], 'incident_ids.*' => ['integer', 'distinct', 'exists:it_tickets,id'],
            'control_room_alert_id' => ['sometimes', 'nullable', 'integer', 'exists:control_room_alerts,id'],
        ];
    }
}
