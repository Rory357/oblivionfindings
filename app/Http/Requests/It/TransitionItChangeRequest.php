<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionItChangeRequest extends FormRequest
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
