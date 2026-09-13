<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionItProblemRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableProblemOrNotFound();

        return $this->hasCurrentBrowserActor() && (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->browserActorRules(),
            'expected_version' => ['required', 'integer', 'min:1'],
            'next_action' => ['nullable', 'required_if:workflow_state,waiting', 'string', 'max:2000'],
            'waiting_party' => ['nullable', 'required_if:workflow_state,waiting', Rule::in(['requester', 'vendor', 'approver', 'team', 'change', 'other'])],
            'workflow_state' => ['required', Rule::in(['submitted', 'investigating', 'waiting', 'known_error', 'resolved', 'closed'])],
            'reason' => ['required', 'string', 'max:2000'],
            'resolution_code' => ['nullable', 'required_if:workflow_state,resolved', 'string', 'max:100'],
            'resolution_summary' => ['nullable', 'required_if:workflow_state,resolved', 'string', 'max:5000'],
        ];
    }
}
