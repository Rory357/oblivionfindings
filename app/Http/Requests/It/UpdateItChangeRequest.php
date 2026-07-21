<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItChange;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItChangeRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableChangeOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category' => ['sometimes', Rule::in(ItTicket::CATEGORIES)],
            'priority' => ['sometimes', Rule::in(ItTicket::PRIORITIES)],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'change_type' => ['sometimes', Rule::in(ItChange::TYPES)],
            'risk_level' => ['sometimes', Rule::in(ItChange::RISK_LEVELS)],
            'is_restricted' => ['sometimes', 'boolean'],
            'impact_summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'implementation_plan' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'validation_plan' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'backout_plan' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'maintenance_starts_at' => ['sometimes', 'nullable', 'date'],
            'maintenance_ends_at' => ['sometimes', 'nullable', 'date', 'after:maintenance_starts_at'],
            'actual_outcome' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'validation_result' => ['sometimes', 'nullable', Rule::in(ItChange::VALIDATION_RESULTS)],
            'validation_summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'backout_summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'pir_summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'service_ids' => ['sometimes', 'array'],
            'service_ids.*' => ['integer', 'distinct', 'exists:it_services,id'],
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['integer', 'distinct', 'exists:sites,id'],
            'device_ids' => ['sometimes', 'array'],
            'device_ids.*' => ['integer', 'distinct', 'exists:devices,id'],
            'alert_ids' => ['sometimes', 'array'],
            'alert_ids.*' => ['integer', 'distinct', 'exists:control_room_alerts,id'],
            'incident_ids' => ['sometimes', 'array'],
            'incident_ids.*' => ['integer', 'distinct', 'exists:it_tickets,id'],
            'problem_ids' => ['sometimes', 'array'],
            'problem_ids.*' => ['integer', 'distinct', 'exists:it_tickets,id'],
        ];
    }
}
