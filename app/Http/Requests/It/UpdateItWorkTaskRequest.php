<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItWorkTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItWorkTaskRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTaskOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(array_diff(ItWorkTask::STATUSES, ['completed']))],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:it_teams,id'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'is_required' => ['sometimes', 'boolean'],
            'evidence_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'dependency_ids' => ['sometimes', 'array'],
            'dependency_ids.*' => ['integer', 'distinct', 'exists:it_work_tasks,id'],
        ];
    }
}
