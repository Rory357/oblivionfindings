<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Models\ComplianceObligation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComplianceObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('governance.compliance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'framework' => ['required', 'string', Rule::in(array_keys(ComplianceObligation::frameworkOptions()))],
            'obligation_reference' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'requirements' => 'nullable|string',
            'frequency' => 'nullable|string|in:monthly,quarterly,annual,ad_hoc,event_driven',
            // Blank: worked out from how often the requirement is due.
            'due_date' => 'nullable|date',
            'owner_id' => 'nullable|integer|min:1',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'evidence_required' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return self::plainMessages();
    }

    /**
     * Plain wording for requirement validation (vocabulary.md: "requirement").
     *
     * @return array<string, string>
     */
    public static function plainMessages(): array
    {
        return [
            'framework.required' => 'Choose the law, standard or funding contract this requirement comes from.',
            'framework.in' => 'Choose one of the listed laws, standards or funding contracts.',
            'obligation_reference.max' => 'Keep the reference to 50 characters or fewer.',
            'title.required' => 'Give the requirement a name.',
            'title.max' => 'Keep the name to 255 characters or fewer.',
            'description.required' => 'Describe what this requirement covers.',
            'frequency.in' => 'Choose how often it is due from the list.',
            'due_date.date' => 'Enter the due date as a date.',
            'owner_id.integer' => 'Choose an owner from the list.',
            'priority.in' => 'Choose a priority from the list.',
            'evidence_required.boolean' => 'Choose whether evidence is needed to mark it done.',
        ];
    }
}
