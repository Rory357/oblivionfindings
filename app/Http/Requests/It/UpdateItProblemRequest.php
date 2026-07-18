<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItProblemRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'impact_summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'workaround' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'incident_ids' => ['sometimes', 'array'],
            'incident_ids.*' => ['integer', 'distinct', 'exists:it_tickets,id'],
            'permanent_fix_change_id' => ['sometimes', 'nullable', 'integer', 'exists:it_tickets,id'],
        ];
    }
}
