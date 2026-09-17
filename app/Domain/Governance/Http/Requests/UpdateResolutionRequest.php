<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Http\Requests\Concerns\ResolutionAuthoringRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateResolutionRequest extends FormRequest
{
    use ResolutionAuthoringRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('resolution'));
    }

    public function rules(): array
    {
        return [
            'expected_version' => 'nullable|integer',
            'title' => 'sometimes|string|max:255',
            ...$this->sharedResolutionRules(),
        ];
    }
}
