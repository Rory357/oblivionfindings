<?php

namespace App\Http\Requests\It;

use App\Models\ItTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        $team = $this->route('team');
        $required = $team ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [
                ...$required, 'string', 'max:255',
                Rule::unique('it_teams', 'name')->ignore($team?->id),
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
