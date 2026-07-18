<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class StoreItWorkTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'team_id' => ['nullable', 'integer', 'exists:it_teams,id'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'is_required' => ['sometimes', 'boolean'],
            'evidence_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'dependency_ids' => ['sometimes', 'array'],
            'dependency_ids.*' => ['integer', 'distinct', 'exists:it_work_tasks,id'],
        ];
    }
}
