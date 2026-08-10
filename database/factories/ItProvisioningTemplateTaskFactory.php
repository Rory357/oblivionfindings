<?php

namespace Database\Factories;

use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateTask;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ItProvisioningTemplateTask> */
class ItProvisioningTemplateTaskFactory extends Factory
{
    protected $model = ItProvisioningTemplateTask::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'provisioning_template_id' => ItProvisioningTemplate::factory(),
            'task_key' => Str::slug($title),
            'title' => $title,
            'category' => 'account',
            'action' => 'grant',
            'request_type' => 'account',
            'stage' => 1,
            'sort_order' => 1,
            'dependency_task_keys' => [],
            'trigger_fields' => [],
            'approval_required' => false,
            'evidence_required' => false,
            'due_offset_days' => 0,
            'fulfiller_fields' => ItProvisioningTemplateTask::FULFILLER_FIELDS,
        ];
    }
}
