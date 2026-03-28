<?php

namespace App\Domain\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('action'));
    }

    public function rules(): array
    {
        return [
            'source_type' => 'sometimes|string',
            'source_id' => 'sometimes|integer',
            'description' => 'sometimes|string',
            'assigned_to' => 'sometimes|exists:users,id',
            'due_date' => 'sometimes|date|after:today',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'evidence_required' => 'boolean',
        ];
    }
}
