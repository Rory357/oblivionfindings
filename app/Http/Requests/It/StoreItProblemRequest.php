<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItProblemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', Rule::in(ItTicket::CATEGORIES)],
            'priority' => ['required', Rule::in(ItTicket::PRIORITIES)],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'is_organisation_wide' => ['nullable', 'boolean'],
            'impact_summary' => ['nullable', 'string', 'max:10000'],
            'root_cause' => ['nullable', 'string', 'max:20000'],
            'workaround' => ['nullable', 'string', 'max:20000'],
            'corrective_action' => ['nullable', 'string', 'max:20000'],
            'incident_ids' => ['sometimes', 'array'],
            'incident_ids.*' => ['integer', 'distinct', 'exists:it_tickets,id'],
            'permanent_fix_change_id' => ['nullable', 'integer', 'exists:it_tickets,id'],
        ];
    }
}
