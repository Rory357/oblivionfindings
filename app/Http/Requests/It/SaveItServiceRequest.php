<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Models\ItService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItServiceRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        $service = $this->route('service');
        $required = $service ? ['sometimes', 'required'] : ['required'];

        return [
            ...$this->browserActorRules(),
            'actor_user_id' => ['required_with:request_uuid', 'integer', 'min:1'],
            'request_uuid' => ['sometimes', 'required', 'uuid', ...($service ? ['prohibited'] : [])],
            'configuration_version' => [...($service ? ['required'] : ['sometimes', 'nullable']), 'string', 'regex:/^[a-f0-9]{64}$/'],
            'key' => [
                ...$required, 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                ...($this->filled('request_uuid') ? [] : [Rule::unique('it_services', 'key')->ignore($service?->id)]),
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
