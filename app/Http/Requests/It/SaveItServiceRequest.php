<?php

namespace App\Http\Requests\It;

use App\Models\ItService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        $service = $this->route('service');
        $required = $service ? ['sometimes', 'required'] : ['required'];

        return [
            'key' => [
                ...$required, 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('it_services', 'key')->ignore($service?->id),
            ],
            'name' => [...$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id'),
            ],
            'status' => [...$required, Rule::in(ItService::STATUSES)],
            'criticality' => [...$required, Rule::in(ItService::CRITICALITIES)],
            'is_active' => [...($service ? ['sometimes'] : ['required']), 'boolean'],
        ];
    }
}
