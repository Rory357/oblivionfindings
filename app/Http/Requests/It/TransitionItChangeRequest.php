<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionItChangeRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableChangeOrNotFound();

        return $this->hasCurrentBrowserActor() && (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->browserActorRules(),
            'expected_version' => ['required', 'integer', 'min:1'],
            'next_action' => ['nullable', 'string', 'max:2000'],
            'workflow_state' => ['required', Rule::in([
                'assessment', 'approval_pending', 'approved', 'scheduled', 'implementing',
                'validation', 'completed', 'failed', 'backed_out', 'review', 'rejected',
                'cancelled', 'closed', 'draft',
            ])],
            'reason' => ['required', 'string', 'max:2000'],
            'resolution_code' => ['nullable', 'required_if:workflow_state,completed', 'string', 'max:100'],
            'resolution_summary' => ['nullable', 'required_if:workflow_state,completed', 'string', 'max:5000'],
        ];
    }
}
