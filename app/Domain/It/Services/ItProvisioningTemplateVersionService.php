<?php

namespace App\Domain\It\Services;

use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateTask;
use App\Models\ItProvisioningTemplateVersion;
use DomainException;

final class ItProvisioningTemplateVersionService
{
    /** Call only inside a transaction holding the canonical template row lock. */
    public function current(ItProvisioningTemplate $template): ItProvisioningTemplateVersion
    {
        if ($template->current_version_id === null) {
            return $this->record($template, null, 'legacy_current');
        }
        $version = $template->currentVersion()->firstOrFail();
        if ((int) $version->provisioning_template_id !== (int) $template->id
            || $version->version !== $template->lock_version
            || $version->contract != $this->contract($template)) {
            throw new DomainException('The template changed outside its saved version. Review its configuration before launching work.');
        }

        return $version;
    }

    /** The parent and tasks must already be locked and saved together. */
    public function record(ItProvisioningTemplate $template, ?int $actorId, string $provenance = 'author_saved'): ItProvisioningTemplateVersion
    {
        $version = ItProvisioningTemplateVersion::query()->create([
            'provisioning_template_id' => $template->id,
            'version' => $template->lock_version,
            'contract' => $this->contract($template),
            'provenance' => $provenance,
            'recorded_by_user_id' => $actorId,
        ]);
        $template->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }

    private function contract(ItProvisioningTemplate $template): array
    {
        return [
            ...$template->only(['name', 'description', 'lifecycle_type', 'position_role', 'site_id',
                'employment_type', 'selection_priority', 'is_active']),
            'tasks' => $template->tasks()->get()->map(fn (ItProvisioningTemplateTask $task): array => $task->only([
                'task_key', 'title', 'description', 'category', 'action', 'request_type',
                'responsible_team_id', 'stage', 'sort_order', 'dependency_task_keys',
                'trigger_fields', 'approval_required', 'evidence_required', 'due_offset_days', 'fulfiller_fields',
            ]))->all(),
        ];
    }
}
