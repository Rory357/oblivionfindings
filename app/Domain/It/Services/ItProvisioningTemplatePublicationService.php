<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateTask;
use App\Models\ItProvisioningTemplateVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Author drafts and live launch instructions have separate immutable pointers. */
final class ItProvisioningTemplatePublicationService
{
    public function canView(User $actor, ItProvisioningTemplate $template): bool
    {
        return $this->canViewContract($actor, ['site_id' => $template->site_id])
            && ($template->publishedVersion === null || $this->canViewVersion($actor, $template->publishedVersion));
    }

    /** Each retained version has its own original Site boundary. */
    public function canViewVersion(User $actor, ItProvisioningTemplateVersion $version): bool
    {
        return $this->canViewContract($actor, $version->contract);
    }

    private function canViewContract(User $actor, array $contract): bool
    {
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')
            || ! app(HrCurrentStaffService::class)->isCurrent($actor)) {
            return false;
        }
        $siteId = $contract['site_id'] ?? null;
        if ($siteId === null) {
            return true;
        }
        if (filter_var($siteId, FILTER_VALIDATE_INT) === false || (int) $siteId < 1) {
            return false;
        }

        return Site::query()->whereKey($siteId)->where('is_active', true)->where('archived', false)->whereNull('archived_at')->exists()
            && ($actor->canDo('it.organisationWide')
                || in_array((int) $siteId, app(ItWorkAccessService::class)->approvedSiteIds($actor), true));
    }

    public function publish(ItProvisioningTemplate $template, User $actor, bool $publish, array $input): ItProvisioningTemplate
    {
        return DB::transaction(function () use ($template, $actor, $publish, $input): ItProvisioningTemplate {
            $template = ItProvisioningTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canView($actor, $template), 404);
            abort_unless((int) ($input['expected_version'] ?? 0) === (int) $template->lock_version
                && (int) ($input['expected_published_version_id'] ?? 0) === (int) $template->published_version_id, 409,
                'The saved or published template changed. Reload and review its current state.');
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reason === '') {
                throw new DomainException('Record why this template is being published or withdrawn.');
            }
            $before = $template->published_version_id;
            $version = $publish ? app(ItProvisioningTemplateVersionService::class)->current($template) : null;
            if ($version) {
                if (! ($version->contract['is_active'] ?? false)) {
                    throw new DomainException('Save an enabled author version before publishing it.');
                }
                $this->tasks($version);
            }
            $template->update(['published_version_id' => $version?->id, 'published_at' => $publish ? now() : null]);
            AuditLogger::logOrFail('it.provisioning.template.'.($publish ? 'published' : 'unpublished'), $template, [
                'actor_id' => $actor->id, 'original_published_version_id' => $before,
                'published_version_id' => $version?->id, 'reason' => $reason,
            ]);

            return $template->refresh();
        }, 3);
    }

    public function resolve(HrEmployeeProfile $profile, string $lifecycle, bool $lock = false, ?int $versionId = null, bool $retained = false): ?ItProvisioningTemplate
    {
        $pinned = $retained && $versionId !== null ? ItProvisioningTemplateVersion::query()->find($versionId) : null;
        $templates = ItProvisioningTemplate::query()->whereNotNull('published_version_id')
            ->when($versionId !== null, fn ($query) => $pinned ? $query->whereKey($pinned->provisioning_template_id) : $query->where('published_version_id', $versionId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->with('publishedVersion')->orderBy('id')->get();
        if ($pinned) {
            $templates->each(fn ($template) => $template->setRelation('publishedVersion', $pinned));
        }

        return $templates->filter(function (ItProvisioningTemplate $template) use ($profile, $lifecycle): bool {
            $version = $template->publishedVersion;
            if (! $version || (int) $version->provisioning_template_id !== (int) $template->id) {
                return false;
            }
            $contract = $version->contract;
            if (($contract['lifecycle_type'] ?? null) !== $lifecycle || ! ($contract['is_active'] ?? false)) {
                return false;
            }
            foreach (['site_id' => 'primary_site_id', 'position_role' => 'position_role', 'employment_type' => 'employment_type'] as $field => $profileField) {
                if (($contract[$field] ?? null) !== null && (string) $contract[$field] !== (string) $profile->{$profileField}) {
                    return false;
                }
            }

            return true;
        })->sortByDesc(function (ItProvisioningTemplate $template): array {
            $contract = $template->publishedVersion->contract;

            return [(isset($contract['position_role']) ? 4 : 0) + (isset($contract['site_id']) ? 2 : 0)
                + (isset($contract['employment_type']) ? 1 : 0), (int) ($contract['selection_priority'] ?? 0), (int) $template->id];
        })->first();
    }

    public function tasks(ItProvisioningTemplateVersion $version): Collection
    {
        $tasks = collect($version->contract['tasks'] ?? []);
        if ($tasks->isEmpty() || $tasks->count() > 50 || $tasks->pluck('task_key')->unique()->count() !== $tasks->count()) {
            throw new DomainException('The saved template has no valid task graph. Review and save a corrected author version.');
        }
        $keys = $tasks->keyBy('task_key');
        foreach ($tasks as $task) {
            foreach ($task['dependency_task_keys'] ?? [] as $key) {
                $dependency = $keys->get($key);
                if (! $dependency || (int) $dependency['stage'] >= (int) $task['stage']) {
                    throw new DomainException('Every dependency must name an existing task in an earlier stage.');
                }
            }
        }

        return $tasks->map(fn (array $task) => new ItProvisioningTemplateTask($task))->sortBy([
            ['stage', 'asc'], ['sort_order', 'asc'], ['task_key', 'asc'],
        ])->values();
    }
}
