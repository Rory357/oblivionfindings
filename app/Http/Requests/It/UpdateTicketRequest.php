<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the ticket properties rail before the locked triage lifecycle
 * repeats authorization, Site, assignee, Asset and approval checks.
 */
class UpdateTicketRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Settlement always uses the reasoned resolve/close journeys.
            'status' => ['sometimes', Rule::in(ItTicket::OPEN_STATUSES)],
            'priority' => ['sometimes', Rule::in(ItTicket::PRIORITIES)],
            'work_type' => ['sometimes', Rule::in(ItTicket::INTAKE_WORK_TYPES)],
            'it_service_id' => ['sometimes', 'nullable', 'integer', 'exists:it_services,id'],
            'category' => ['sometimes', Rule::in(ItTicket::CATEGORIES)],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:255'],
            'asset_id' => ['sometimes', 'nullable', 'integer', 'exists:assets,id'],
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
            'is_organisation_wide' => ['sometimes', 'boolean'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'waiting_reason' => ['required_if:status,waiting', 'nullable', 'string', 'max:1000'],
            'waiting_party' => ['required_if:status,waiting', 'nullable', Rule::in(['requester', 'vendor', 'approver', 'team', 'change', 'other'])],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'resolution_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
