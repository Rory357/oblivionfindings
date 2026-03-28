<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Models\ComplianceObligation;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ComplianceObligation::class);
    }

    public function rules(): array
    {
        return [
            'framework' => 'required|string',
            'obligation_reference' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'requirements' => 'nullable|string',
            'due_date' => 'nullable|date',
            'owner_id' => 'nullable|exists:users,id',
            'priority' => 'nullable|string|in:low,medium,high,critical',
        ];
    }
}
