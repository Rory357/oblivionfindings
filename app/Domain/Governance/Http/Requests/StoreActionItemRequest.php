<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Models\ActionItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreActionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ActionItem::class);
    }

    public function rules(): array
    {
        return [
            'source_type' => 'required|string',
            'source_id' => 'required|integer',
            'description' => 'required|string',
            'assigned_to' => 'required|exists:users,id',
            'due_date' => 'required|date|after:today',
            'priority' => 'required|in:low,medium,high,critical',
            'evidence_required' => 'boolean',
        ];
    }
}
