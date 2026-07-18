<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionItProblemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'workflow_state' => ['required', Rule::in(['investigating', 'known_error', 'resolved', 'closed'])],
            'reason' => ['required', 'string', 'max:2000'],
            'resolution_code' => ['nullable', 'required_if:workflow_state,resolved', 'string', 'max:100'],
            'resolution_summary' => ['nullable', 'required_if:workflow_state,resolved', 'string', 'max:5000'],
        ];
    }
}
