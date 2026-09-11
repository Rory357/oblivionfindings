<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Models\ItTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItTeamRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        $team = $this->route('team');
        $required = $team ? ['sometimes', 'required'] : ['required'];

        return [
            ...$this->browserActorRules(),
            'actor_user_id' => ['required_with:request_uuid', 'integer', 'min:1'],
            'request_uuid' => ['sometimes', 'required', 'uuid', ...($team ? ['prohibited'] : [])],
            'configuration_version' => [...($team ? ['required'] : ['sometimes', 'nullable']), 'string', 'regex:/^[a-f0-9]{64}$/'],
            'name' => [
                ...$required, 'string', 'max:255',
                ...($this->filled('request_uuid') ? [] : [Rule::unique('it_teams', 'name')->ignore($team?->id)]),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'manager_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id'),
            ],
            'is_active' => [...($team ? ['sometimes'] : ['required']), 'boolean'],
            'members' => ['sometimes', 'array', 'max:200'],
            'members.*.user_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('users', 'id'),
            ],
            'members.*.role' => ['required', Rule::in(ItTeam::MEMBER_ROLES)],
        ];
    }
}
