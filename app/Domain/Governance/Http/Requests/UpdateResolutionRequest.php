<?php

namespace App\Domain\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('resolution'));
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'context' => 'sometimes|string|min:50',
            'recommendation' => 'nullable|string',
        ];
    }
}
