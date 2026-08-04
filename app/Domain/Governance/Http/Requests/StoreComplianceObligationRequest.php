<?php

namespace App\Domain\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('governance.compliance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'framework' => 'required|string',
            'obligation_reference' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'requirements' => 'nullable|string',
            'frequency' => 'nullable|string|in:monthly,quarterly,annual,ad_hoc,event_driven',
            'due_date' => 'nullable|date',
            'owner_id' => 'nullable|integer|min:1',
            'priority' => 'nullable|string|in:low,medium,high,critical',
        ];
    }
}
