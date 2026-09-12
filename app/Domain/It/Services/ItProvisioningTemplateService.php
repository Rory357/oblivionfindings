<?php

namespace App\Domain\It\Services;

use App\Models\ItProvisioningTemplate;
use App\Models\ItTeam;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ItProvisioningTemplateService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItProvisioningTemplateVersionService $versions,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItProvisioningTemplate
    {
        $this->guard($actor);

        return DB::transaction(function () use ($actor, $data): ItProvisioningTemplate {
            $actor = $actor->fresh() ?? throw new DomainException('Your account is no longer available.');
            $this->guard($actor);
            $this->guardData($actor, $data);
            $template = ItProvisioningTemplate::query()->create([
                'lock_version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                ...Arr::only($data, [
                    'name', 'description', 'lifecycle_type', 'position_role', 'site_id',
                    'employment_type', 'selection_priority', 'is_active',
                ]),
            ]);
            $this->replaceTasks($template, $data['tasks']);
            $version = $this->versions->record($template->refresh(), (int) $actor->id);
            AuditLogger::logOrFail('it.provisioning.template.created', $template, [
                'application_scope' => 'single_installation',
                'actor_id' => $actor->id,
                'lifecycle_type' => $template->lifecycle_type,
                'task_count' => count($data['tasks']),
                'template_version_id' => $version->id,
            ]);

            return $template->load('tasks.responsibleTeam:id,name');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItProvisioningTemplate $template, User $actor, array $data): ItProvisioningTemplate
    {
        $this->guard($actor);

        return DB::transaction(function () use ($template, $actor, $data): ItProvisioningTemplate {
            $actor = $actor->fresh() ?? throw new DomainException('Your account is no longer available.');
            $this->guard($actor);
            $template = ItProvisioningTemplate::query()
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            // Check the existing record before considering a replacement Site.
            abort_unless($template->site_id === null || $actor->canDo('it.organisationWide')
                || in_array((int) $template->site_id, $this->workAccess->approvedSiteIds($actor), true), 404);
            if (! isset($data['expected_version']) || (int) $data['expected_version'] !== $template->lock_version) {
                throw ValidationException::withMessages(['expected_version' => 'This template has changed. Reload the saved version and review your changes before saving.']);
            }
            $this->guardData($actor, $data);
            if ($template->current_version_id === null) {
                $this->versions->current($template);
            }
            // A reviewed full save may repair out-of-band mutable drift.
            // Preserve existing immutable evidence; never rewrite that version.
            $template->update([
                'lock_version' => $template->lock_version + 1,
                'updated_by_user_id' => $actor->id,
                ...Arr::only($data, [
                    'name', 'description', 'lifecycle_type', 'position_role', 'site_id',
                    'employment_type', 'selection_priority', 'is_active',
                ]),
            ]);
            $this->replaceTasks($template, $data['tasks']);
            $version = $this->versions->record($template, (int) $actor->id);
            AuditLogger::logOrFail('it.provisioning.template.updated', $template, [
                'application_scope' => 'single_installation',
                'actor_id' => $actor->id,
                'lifecycle_type' => $template->lifecycle_type,
                'task_count' => count($data['tasks']),
                'template_version_id' => $version->id,
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

    private function guard(User $actor): void
    {
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')) {
            throw new DomainException('You are not allowed to manage provisioning templates.');
        }
    }

    /** @param array<string, mixed> $data */
    private function guardData(User $actor, array $data): void
    {
        $siteId = is_numeric($data['site_id'] ?? null) ? (int) $data['site_id'] : null;
        if ($siteId !== null) {
            $siteIsOperational = Site::query()
                ->whereKey($siteId)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->exists();
            if (! $siteIsOperational
                || (! $actor->canDo('it.organisationWide')
                    && ! in_array($siteId, $this->workAccess->approvedSiteIds($actor), true))) {
                throw new DomainException('Provisioning templates can only use an approved active Site.');
            }
        }

        $teamIds = collect((array) ($data['tasks'] ?? []))
            ->pluck('responsible_team_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        if ($teamIds->isNotEmpty()
            && ItTeam::query()->whereKey($teamIds)->where('is_active', true)->count() !== $teamIds->count()) {
            throw new DomainException('Provisioning tasks can only use active IT teams.');
        }
    }
}
