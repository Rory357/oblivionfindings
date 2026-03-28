<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Models\Resolution;
use Illuminate\Foundation\Http\FormRequest;

class StoreResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Resolution::class);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:ordinary,special,unanimous',
            'voting_deadline' => 'nullable|date|after:now',
            'meeting_id' => 'nullable|exists:governance_meetings,id',
        ];
    }
}
