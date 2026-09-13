<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Http\Requests\It\Concerns\HasItDraftCommit;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the ticket properties rail before the locked triage lifecycle
 * repeats authorization, Site, assignee, Asset and approval checks.
 */
class UpdateTicketRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;
    use HasItDraftCommit;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return $this->hasCurrentBrowserActor() && (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->browserActorRules(),
            ...$this->draftCommitRules(),
            'expected_version' => ['required', 'integer', 'min:1'],
            // Settlement always uses the reasoned resolve/close journeys.
            'status' => ['sometimes', Rule::in(ItTicket::OPEN_STATUSES)],
            'priority' => ['sometimes', Rule::in(ItTicket::PRIORITIES)],
            'impact' => ['sometimes', Rule::in(ItTicket::IMPACTS)],
            'urgency' => ['sometimes', Rule::in(ItTicket::URGENCIES)],
            'priority_reason' => ['nullable', 'string', 'max:1000'],
            'release_priority_override' => ['sometimes', 'boolean'],
            'routing_reason' => ['nullable', 'string', 'max:1000'],
            'release_routing_override' => ['sometimes', 'boolean'],
            'queue_id' => ['sometimes', 'integer', 'exists:it_queues,id'],
            'owner_user_id' => ['sometimes', 'integer', 'exists:users,id'],
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
