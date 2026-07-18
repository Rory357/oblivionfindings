<?php

namespace App\Http\Requests\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreItProvisioningTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lifecycle_type' => ['required', Rule::in(ItProvisioningTemplate::LIFECYCLE_TYPES)],
            'position_role' => ['nullable', 'string', 'max:100'],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('tenant_id', $tenantId)],
            'employment_type' => ['nullable', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'selection_priority' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.task_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'distinct'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string', 'max:2000'],
            'tasks.*.category' => ['required', Rule::in(ItProvisioningTemplateTask::CATEGORIES)],
            'tasks.*.action' => ['required', Rule::in(ItProvisioningTemplateTask::ACTIONS)],
            'tasks.*.request_type' => ['required', Rule::in(ItProvisioningRequest::TYPES)],
            'tasks.*.responsible_team_id' => [
                'nullable', 'integer', Rule::exists('it_teams', 'id')->where('tenant_id', $tenantId),
            ],
            'tasks.*.stage' => ['required', 'integer', 'min:1', 'max:50'],
            'tasks.*.sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'tasks.*.dependency_task_keys' => ['present', 'array'],
            'tasks.*.dependency_task_keys.*' => ['string', 'max:100'],
            'tasks.*.trigger_fields' => ['present', 'array'],
            'tasks.*.trigger_fields.*' => ['string', Rule::in(ItProvisioningTemplateTask::TRIGGER_FIELDS)],
            'tasks.*.approval_required' => ['required', 'boolean'],
            'tasks.*.evidence_required' => ['required', 'boolean'],
            'tasks.*.due_offset_days' => ['required', 'integer', 'min:-365', 'max:365'],
            'tasks.*.fulfiller_fields' => ['present', 'array'],
            'tasks.*.fulfiller_fields.*' => ['string', Rule::in(ItProvisioningTemplateTask::FULFILLER_FIELDS)],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $tasks = collect($this->input('tasks', []))->keyBy('task_key');
            foreach ($tasks as $key => $task) {
                foreach ((array) ($task['dependency_task_keys'] ?? []) as $dependencyKey) {
                    $dependency = $tasks->get($dependencyKey);
                    if (! $dependency) {
                        $validator->errors()->add('tasks', "Task {$key} depends on an unknown task key.");
                    } elseif ($dependencyKey === $key || (int) ($dependency['stage'] ?? 1) >= (int) ($task['stage'] ?? 1)) {
                        $validator->errors()->add('tasks', "Task {$key} must depend on a task in an earlier stage.");
                    }
                }
            }
        }];
    }

    private function tenantId(): int
    {
        foreach (['tenant_id', 'organization_id'] as $attribute) {
            $value = $this->user()->getAttribute($attribute);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return (int) (HrEmployeeProfile::query()
            ->where('user_id', $this->user()->id)
            ->value('tenant_id') ?? 1);
    }
}
