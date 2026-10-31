<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Tasks\TaskAggregator;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Current worker authority and snapshot audiences, shared by document reads and commands. */
class SafeWorkProcedureAccessService
{
    public const PERMISSIONS = ['procedures.view', 'procedures.create', 'procedures.manage', 'procedures.approve', 'healthSafety.viewAllSites', 'hazards.view', 'hazards.manage'];

    public function __construct(private readonly UserSiteAccessService $sites, private readonly AuthorizationEvidenceLockService $authorization) {}

    /** Reuses only evidence freshly prepared inside this active read callback. */
    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, $operation);
    }

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($viewer, $operation) {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            abort_unless($current->approved_at !== null && ! $current->isExternalClinicianAccount(), 403);
            abort_unless($reads->query(User::query()->staff())->whereKey($current->id)->exists(), 403);
            $profile = $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first();
            $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
            abort_unless($profile && $profile->is_active && ($profile->start_date === null || $profile->start_date->toDateString() <= $today) && ($profile->end_date === null || $profile->end_date->toDateString() >= $today), 403);
            $current->setRelation('hrEmployeeProfile', $profile);
            $siteIds = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless($siteIds !== [], 403);

            return $operation($current, $reads, $siteIds);
        }));
    }

    public function audienceAllows(array $snapshot, User $viewer, array $siteIds, bool $working = false): bool
    {
        $known = is_array($snapshot['applicable_roles'] ?? null) && is_array($snapshot['applicable_sites'] ?? null);
        $legacy = ($snapshot['_publication_provenance'] ?? null) === 'legacy_current_capture';
        if (! $known && ! $legacy && ! ($working && $viewer->canDo('procedures.approve') && $viewer->canDo('healthSafety.viewAllSites'))) {
            return false;
        }
        $sites = array_map('intval', is_array($snapshot['applicable_sites'] ?? null) ? $snapshot['applicable_sites'] : []);
        if ($sites !== [] && array_intersect($sites, $siteIds) === []) {
            return false;
        }
        $roles = is_array($snapshot['applicable_roles'] ?? null) ? $snapshot['applicable_roles'] : [];
        $roleKeys = $viewer->roles->pluck('name')->push($viewer->role)->filter()->all();

        return $roles === [] || array_intersect($roles, $roleKeys) !== [] || ($working && $viewer->canDo('procedures.approve') && $viewer->canDo('healthSafety.viewAllSites'));
    }

    public function recordForViewer(SafeWorkProcedure $record, User $viewer): SafeWorkProcedure
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $siteIds) use ($record) {
            abort_unless($current->canDo('procedures.view'), 403);
            $fresh = $reads->query(SafeWorkProcedure::query())->findOrFail($record->id);
            $fresh->load(['publishedVersion', 'versions', 'attachments.uploader:id,name', 'owner:id,name', 'approvedBy:id,name']);
            $publication = $fresh->publishedVersion;
            $working = $this->workingAllowed($fresh, $current, $siteIds);
            $historical = $fresh->versions->contains(fn ($version) => $this->isApprovedVersion($version) && $this->audienceAllows($version->content_snapshot ?? [], $current, $siteIds));
            abort_unless(($fresh->status !== 'archived' && $publication && $this->audienceAllows($publication->content_snapshot ?? [], $current, $siteIds)) || $working || $historical, 403);

            return $fresh;
        });
    }

    public function isApprovedVersion(SafeWorkProcedureVersion $version): bool
    {
        return $version->provenance === 'legacy_current_capture' || ($version->provenance === 'controlled' && $version->approved_by !== null && $version->approved_at !== null);
    }

    public function canViewVersion(SafeWorkProcedure $record, SafeWorkProcedureVersion $version, User $viewer): bool
    {
        if ((int) $version->safe_work_procedure_id !== (int) $record->id) {
            return false;
        }

        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record, $version) {
            $fresh = $reads->query(SafeWorkProcedure::query())->findOrFail($record->id);
            $snapshot = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $fresh->id)->findOrFail($version->id);

            return $current->canDo('procedures.view') && ($this->isApprovedVersion($snapshot) ? $this->audienceAllows($snapshot->content_snapshot ?? [], $current, $sites) : $this->workingAllowed($fresh, $current, $sites) && $this->audienceAllows($snapshot->content_snapshot ?? [], $current, $sites, true));
        });
    }

    public function canAcknowledge(SafeWorkProcedure $record, SafeWorkProcedureVersion $version, User $viewer): bool
    {
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record, $version) {
                $fresh = $reads->query(SafeWorkProcedure::query())->findOrFail($record->id);
                $copy = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $fresh->id)->findOrFail($version->id);

                return $fresh->status !== 'archived' && (int) $fresh->published_version_id === (int) $copy->id && $this->isApprovedVersion($copy) && $current->canDo('procedures.view') && $this->audienceAllows($copy->content_snapshot ?? [], $current, $sites);
            });
        } catch (HttpException $error) {
            if ($error->getStatusCode() === 403) {
                return false;
            } throw $error;
        }
    }

    private function recordDecision(SafeWorkProcedure $record, User $viewer, callable $decision): bool
    {
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record, $decision) {
                $fresh = $reads->query(SafeWorkProcedure::query())->findOrFail($record->id);

                return $decision($fresh, $current, $sites);
            });
        } catch (HttpException $error) {
            if ($error->getStatusCode() === 403) {
                return false;
            } throw $error;
        }
    }

    public function canReadWorking(SafeWorkProcedure $record, User $viewer): bool
    {
        return $this->recordDecision($record, $viewer, fn ($fresh, $current, $sites) => $this->workingAllowed($fresh, $current, $sites));
    }

    public function canManage(SafeWorkProcedure $record, User $viewer): bool
    {
        return $this->recordDecision($record, $viewer, fn ($fresh, $current, $sites) => $current->canDo('procedures.manage') && $this->workingAllowed($fresh, $current, $sites) && $this->allSitesAllowed($fresh->applicable_sites ?? [], $sites, $current));
    }

    public function canApprove(SafeWorkProcedure $record, User $viewer): bool
    {
        return $this->recordDecision($record, $viewer, fn ($fresh, $current, $sites) => $current->canDo('procedures.approve') && $this->workingAllowed($fresh, $current, $sites) && $this->allSitesAllowed($fresh->applicable_sites ?? [], $sites, $current));
    }

    public function workingAllowed(SafeWorkProcedure $record, User $viewer, array $siteIds): bool
    {
        return $viewer->canDo('procedures.view') && ($viewer->canDo('procedures.manage') || $viewer->canDo('procedures.approve') || ($viewer->canDo('procedures.create') && (int) $record->created_by === (int) $viewer->id)) && $this->audienceAllows($record->only(['applicable_sites', 'applicable_roles']), $viewer, $siteIds, true);
    }

    public function allSitesAllowed(array $audience, array $siteIds, ?User $actor = null): bool
    {
        return ($audience !== [] || ($actor && $actor->canDo('healthSafety.viewAllSites'))) && array_diff(array_map('intval', $audience), $siteIds) === [];
    }

    private function decision(User $viewer, callable $decision): bool
    {
        try {
            return $this->withCurrentViewer($viewer, fn ($current, $reads, $sites) => (bool) $decision($current, $sites));
        } catch (HttpException $error) {
            if ($error->getStatusCode() === 403) {
                return false;
            } throw $error;
        }
    }

    public function publishedForAudience(User $viewer, ?int $siteId = null, ?array $subjectRoleKeys = null, ?array $subjectSiteIds = null): Collection
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $siteIds) use ($siteId, $subjectRoleKeys, $subjectSiteIds) {
            if (! $current->canDo('procedures.view') || ($siteId !== null && ! in_array($siteId, $siteIds, true))) {
                return collect();
            }

            return $reads->query(SafeWorkProcedure::query()->published())->with('publishedVersion')->orderBy('id')->get()->filter(function ($record) use ($current, $siteIds, $siteId, $subjectRoleKeys, $subjectSiteIds) {
                $snapshot = $record->publishedSnapshot();
                if (! $this->audienceAllows($snapshot, $current, $siteIds)) {
                    return false;
                }
                $sites = array_map('intval', $snapshot['applicable_sites'] ?? []);
                $roles = $snapshot['applicable_roles'] ?? [];

                return ($siteId === null || $sites === [] || in_array($siteId, $sites, true)) && ($subjectSiteIds === null || $sites === [] || array_intersect($sites, $subjectSiteIds) !== []) && ($subjectRoleKeys === null || $roles === [] || array_intersect($roles, $subjectRoleKeys) !== []);
            })->values();
        });
    }

    public function pickerOptions(User $viewer, ?SafeWorkProcedure $record = null): array
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $siteIds) use ($record) {
            $owners = $reads->query(User::query()->whereNotNull('approved_at'))->orderBy('name')->get(['id', 'name'])->filter(function ($candidate) use ($record, $siteIds) {
                try {
                    return $this->withCurrentViewer($candidate, fn ($owner, $ownerReads, $ownerSites) => $owner->canDo('procedures.manage') && ($record ? $this->workingAllowed($record, $owner, $ownerSites) && $this->allSitesAllowed($record->applicable_sites ?? [], $ownerSites, $owner) : array_intersect($ownerSites, $siteIds) !== []));
                } catch (HttpException) {
                    return false;
                }
            })->values();

            return ['categories' => collect(SafeWorkProcedureService::CATEGORIES)->map(fn ($key) => ['value' => $key, 'label' => ucwords(str_replace('_', ' ', $key))])->all(), 'can_apply_organisation_wide' => $current->canDo('healthSafety.viewAllSites'), 'sites' => $reads->query(Site::query())->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name']), 'roles' => $reads->query(Role::query())->orderBy('label')->get(['id', 'name', 'label']), 'trainingCourses' => $reads->query(TrainingCourse::query()->active())->orderBy('name')->get(['id', 'name', 'code']), 'owners' => $owners];
        });
    }

    public function assertSource(ProcedureReviewRequest|array $request, User $current, CurrentAuthorizationReads $reads, array $siteIds, bool $manage = false, ?HealthSafetyReadContext $context = null): HsEvent
    {
        $data = is_array($request) ? $request : $request->getAttributes();
        abort_unless($current->canDo('hazards.view') && (! $manage || $current->canDo('hazards.manage')), 403);
        $event = $reads->query(HsEvent::query())->find($data['hs_event_id'] ?? null);
        abort_unless($event && $event->site_id && in_array((int) $event->site_id, $siteIds, true), 403);
        if (isset($data['source_site_id'])) {
            abort_unless((int) $data['source_site_id'] === (int) $event->site_id, 403);
        }
        abort_unless(app(HsEventAccessService::class)->canView($event, $current, $context), 403);
        if (! empty($data['hs_corrective_action_id'])) {
            $action = $reads->query(HsCorrectiveAction::query())->find($data['hs_corrective_action_id']);
            abort_unless($action && (int) $action->hs_event_id === (int) $event->id, 403);
            abort_unless(app(TaskAggregator::class)->findItemFor($current, 'corrective_action', (int) $action->id, ['include_done' => true]), 403);
        }

        return $event;
    }

    public function sourceCan(HsEvent $event, User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        try {
            $context?->assertViewer($viewer);
            $operation = function ($current, $reads, $sites) use ($event, $context) {
                $this->assertSource(['hs_event_id' => $event->id], $current, $reads, $sites, false, $context);
                $view = $current->canDo('procedures.view');

                return ['view_procedure_requests' => $view, 'request_procedure_review' => $view && $current->canDo('hazards.manage')];
            };

            return $context !== null ? $this->withReadContext($context, $operation) : $this->withCurrentViewer($viewer, $operation);
        } catch (HttpException) {
            return ['view_procedure_requests' => false, 'request_procedure_review' => false];
        }
    }

    public function reviewRequestsForViewer(SafeWorkProcedure $record, User $viewer): Collection
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record) {
            return $reads->query(ProcedureReviewRequest::query()->where('safe_work_procedure_id', $record->id))->with(['requestedVersion', 'resultVersion', 'hsEvent.site', 'hsCorrectiveAction', 'requestedBy', 'assignedTo', 'decidedBy'])->orderBy('id')->get()->filter(function ($row) use ($current, $reads, $sites, $record) {
                try {
                    $this->assertSource($row, $current, $reads, $sites);

                    return (int) $row->requestedVersion?->safe_work_procedure_id === (int) $record->id && ($row->result_version_id === null || (int) $row->resultVersion?->safe_work_procedure_id === (int) $record->id);
                } catch (HttpException) {
                    return false;
                }
            })->values();
        });
    }

    public function canManageReviewRequests(SafeWorkProcedure $record, User $viewer): bool
    {
        return $this->canManage($record, $viewer);
    }

    public function reviewRequestCan(SafeWorkProcedure $record, ProcedureReviewRequest $request, User $viewer): array
    {
        $can = array_fill_keys(['start', 'resolve', 'decline', 'cancel', 'reassign'], false);
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record, $request) {
                $this->assertSource($request, $current, $reads, $sites);
                $version = $reads->query(SafeWorkProcedureVersion::query())->where('safe_work_procedure_id', $record->id)->find($request->requested_version_id);
                abort_unless($current->canDo('procedures.view') && $version && $this->isApprovedVersion($version) && $this->audienceAllows($version->content_snapshot ?? [], $current, $sites), 403);
                $live = in_array($request->status, ['requested', 'in_review'], true) && $record->status !== 'archived';
                $manager = $current->canDo('procedures.manage') && $this->workingAllowed($record, $current, $sites) && $this->allSitesAllowed($record->applicable_sites ?? [], $sites, $current);
                $executor = $manager && (int) $request->assigned_to_user_id === (int) $current->id;

                return ['start' => $executor && $live && $request->status === 'requested', 'resolve' => $executor && $live && $request->status === 'in_review', 'decline' => $executor && $live, 'cancel' => $live && ((int) $request->requested_by_user_id === (int) $current->id || $manager), 'reassign' => $manager && $live];
            });
        } catch (HttpException) {
            return $can;
        }
    }
}
