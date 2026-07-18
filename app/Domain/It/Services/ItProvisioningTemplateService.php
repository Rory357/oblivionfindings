<?php

namespace App\Domain\It\Services;

use App\Models\ItProvisioningTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ItProvisioningTemplateService
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, int $tenantId, array $data): ItProvisioningTemplate
    {
        $this->guard($actor, $tenantId);

        return DB::transaction(function () use ($actor, $tenantId, $data): ItProvisioningTemplate {
            $template = ItProvisioningTemplate::query()->create([
                'tenant_id' => $tenantId,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                ...Arr::only($data, [
                    'name', 'description', 'lifecycle_type', 'position_role', 'site_id',
                    'employment_type', 'selection_priority', 'is_active',
                ]),
            ]);
            $this->replaceTasks($template, $data['tasks']);
            AuditLogger::logOrFail('it.provisioning.template.created', $template, [
                'organization_id' => $tenantId,
                'actor_id' => $actor->id,
                'lifecycle_type' => $template->lifecycle_type,
                'task_count' => count($data['tasks']),
            ]);

            return $template->load('tasks.responsibleTeam:id,name');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItProvisioningTemplate $template, User $actor, int $tenantId, array $data): ItProvisioningTemplate
    {
        $this->guard($actor, $tenantId);
        if ((int) $template->tenant_id !== $tenantId) {
            throw new DomainException('That provisioning template belongs to another organisation.');
        }

        return DB::transaction(function () use ($template, $actor, $tenantId, $data): ItProvisioningTemplate {
            $template->update([
                'updated_by_user_id' => $actor->id,
                ...Arr::only($data, [
                    'name', 'description', 'lifecycle_type', 'position_role', 'site_id',
                    'employment_type', 'selection_priority', 'is_active',
                ]),
            ]);
            $this->replaceTasks($template, $data['tasks']);
            AuditLogger::logOrFail('it.provisioning.template.updated', $template, [
                'organization_id' => $tenantId,
                'actor_id' => $actor->id,
                'lifecycle_type' => $template->lifecycle_type,
                'task_count' => count($data['tasks']),
            ]);

            return $template->load('tasks.responsibleTeam:id,name');
        });
    }

    /** @param array<int, array<string, mixed>> $tasks */
    private function replaceTasks(ItProvisioningTemplate $template, array $tasks): void
    {
        $template->tasks()->delete();
        foreach ($tasks as $task) {
            $template->tasks()->create(Arr::only($task, [
                'task_key', 'title', 'description', 'category', 'action', 'request_type',
                'responsible_team_id', 'stage', 'sort_order', 'dependency_task_keys',
                'trigger_fields', 'approval_required', 'evidence_required', 'due_offset_days',
                'fulfiller_fields',
            ]));
        }
    }

    private function guard(User $actor, int $tenantId): void
    {
        if (! $actor->canDo('it.manage')
            || ($actor->organization_id !== null && (int) $actor->organization_id !== $tenantId)) {
            throw new DomainException('You are not allowed to manage provisioning templates.');
        }
    }
}
