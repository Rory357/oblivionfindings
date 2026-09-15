<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Http\Requests\Concerns\ResolutionAuthoringRules;
use App\Domain\Governance\Models\Resolution;
use Illuminate\Foundation\Http\FormRequest;

class StoreResolutionRequest extends FormRequest
{
    use ResolutionAuthoringRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Resolution::class);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            ...$this->sharedResolutionRules(),
        ];
    }
}
