<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Tasks\TaskAggregator;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One organisation; current internal staff, explicit permissions and approved Sites. */
class EmergencyDrillAccessService
{
    public const PERMISSIONS = ['hazards.view', 'hazards.create', 'hazards.manage', 'healthSafety.viewAllSites'];

    public function __construct(private readonly UserSiteAccessService $sites, private readonly AuthorizationEvidenceLockService $authorization, private readonly HrCurrentStaffService $staff) {}

    /** Reuses only evidence freshly prepared inside this active read callback. */
    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, $operation);
    }

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($viewer, $operation) {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            abort_unless($current->approved_at !== null && ! $current->isExternalClinicianAccount() && $reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
            $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
            $siteIds = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless($siteIds !== [], 403);

            return $operation($current, $reads, $siteIds);
        }));
    }

    public function scopeForSites(Builder $query, array $siteIds): Builder
    {
        return $query->whereIn($query->qualifyColumn('site_id'), $siteIds);
    }

    public function readableQuery(User $viewer): Builder
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $siteIds) {
            abort_unless($current->canDo('hazards.view'), 403);

            return $this->scopeForSites(EmergencyDrill::query(), $siteIds);
        });
    }

    public function recordForViewer(EmergencyDrill $record, User $viewer): EmergencyDrill
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $siteIds) use ($record) {
            abort_unless($current->canDo('hazards.view'), 403);

            return $reads->query($this->scopeForSites(EmergencyDrill::query(), $siteIds))->findOrFail($record->id);
        });
    }

    public function assertRecord(EmergencyDrill $record, User $current, array $siteIds, bool $manage = false): void
    {
        abort_unless($current->canDo('hazards.view') && (! $manage || $current->canDo('hazards.manage')), 403);
        abort_unless(in_array((int) $record->site_id, $siteIds, true), 404);
    }

    public function currentStaffAtSite(int $userId, int $siteId): User
    {
        $candidate = User::query()->findOrFail($userId);

        return $this->withCurrentViewer($candidate, function ($current, $reads, $sites) use ($siteId) {
            abort_unless(in_array($siteId, $sites, true), 403);

            return $current;
        });
    }

    public function canManage(EmergencyDrill $record, User $viewer): bool
    {
        return $this->decision($viewer, function ($current, $reads, $sites) use ($record) {
            $fresh = $reads->query(EmergencyDrill::query())->findOrFail($record->id);
            $this->assertRecord($fresh, $current, $sites, true);

            return true;
        });
    }

    public function canCreate(User $viewer): bool
    {
        return $this->decision($viewer, fn ($current) => $current->canDo('hazards.view') && ($current->canDo('hazards.create') || $current->canDo('hazards.manage')));
    }

    public function commandCan(EmergencyDrill $record, User $viewer): array
    {
        $keys = ['view', 'manage', 'edit', 'start', 'complete', 'cancel', 'add_participant', 'add_finding', 'add_evidence', 'remove_evidence', 'schedule_next', 'export_participants', 'export_evidence'];
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record) {
                $fresh = $reads->query(EmergencyDrill::query())->findOrFail($record->id);
                $this->assertRecord($fresh, $current, $sites);
                $manage = $current->canDo('hazards.manage');
                $live = in_array($fresh->status, ['scheduled', 'in_progress'], true);

                return ['view' => true, 'manage' => $manage, 'edit' => $manage && $fresh->status === 'scheduled',
                    'start' => $manage && $fresh->status === 'scheduled', 'complete' => $manage && $fresh->status === 'in_progress',
                    'cancel' => $manage && $live, 'add_participant' => $manage && $live,
                    'add_finding' => $manage && $fresh->status !== 'cancelled', 'add_evidence' => $manage && $fresh->status !== 'cancelled',
                    'remove_evidence' => $manage && $live, 'schedule_next' => $manage && in_array($fresh->status, ['completed', 'cancelled'], true),
                    'export_participants' => true, 'export_evidence' => true];
            });
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return array_fill_keys($keys, false);
            }
            throw $exception;
        }
    }

    public function findingCan(EmergencyDrill $record, EmergencyDrillFinding $finding, User $viewer): array
    {
        $denied = array_fill_keys(['edit', 'resolve', 'create_action', 'link_action'], false);
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record, $finding, $denied) {
                $fresh = $reads->query(EmergencyDrill::query())->findOrFail($record->id);
                $this->assertRecord($fresh, $current, $sites);
                $child = $reads->query(EmergencyDrillFinding::query())->where('emergency_drill_id', $fresh->id)->findOrFail($finding->id);
                $open = ! in_array($child->status, ['resolved', 'closed', 'accepted'], true);
                $manage = $current->canDo('hazards.manage') && $fresh->status !== 'cancelled' && $open;
                if (! $manage) {
                    return $denied;
                }
                $remedyVerified = true;
                if ($child->hs_corrective_action_id !== null) {
                    $action = $reads->query(HsCorrectiveAction::query())->find($child->hs_corrective_action_id);
                    $event = $action ? $reads->query(HsEvent::query())->find($action->hs_event_id) : null;
                    $remedyVerified = $event && $event->event_category === HsEvent::CATEGORY_DRILL_FAILURE && in_array($event->source_type, array_unique([EmergencyDrill::class, $fresh->getMorphClass()]), true) && (int) $event->source_id === (int) $fresh->id && (int) $event->site_id === (int) $fresh->site_id
                        && app(HsEventAccessService::class)->canView($event, $current)
                        && app(TaskAggregator::class)->findItemFor($current, 'corrective_action', (int) $action->id, ['include_done' => true])
                        && in_array($action->status, [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED], true) && $action->effectiveness_confirmed === true;
                }
                $hasEvidence = $reads->query($fresh->attachments()->getQuery())->where('disk', 'private')->get()->contains(fn ($file) => Storage::disk('private')->exists($file->path));
                $canLink = $child->hs_corrective_action_id === null && $child->finding_type !== 'positive';
                $existingEvent = $reads->query(HsEvent::query())->where('source_type', EmergencyDrill::class)->where('source_id', $fresh->id)->where('site_id', $fresh->site_id)->where('event_category', HsEvent::CATEGORY_DRILL_FAILURE)->first();

                return ['edit' => true, 'resolve' => (bool) $remedyVerified && $hasEvidence, 'create_action' => $canLink && (! $existingEvent || $existingEvent->isOpen()), 'link_action' => $canLink];
            });
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return $denied;
            }
            throw $exception;
        }
    }

    public function pickerOptions(User $viewer, ?EmergencyDrill $record = null): array
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record) {
            abort_unless($current->canDo('hazards.view'), 403);
            if ($record) {
                $this->assertRecord($record, $current, $sites);
            }
            $staff = $reads->query($this->staff->currentUsersQuery())->orderBy('name')->get(['id', 'name'])->filter(function ($candidate) use ($record, $sites) {
                try {
                    return $this->withCurrentViewer($candidate, fn ($worker, $workerReads, $workerSites) => $record ? in_array((int) $record->site_id, $workerSites, true) : array_intersect($workerSites, $sites) !== []);
                } catch (HttpException) {
                    return false;
                }
            })->values();

            return ['sites' => $reads->query(Site::query())->whereIn('id', $sites)->orderBy('name')->get(['id', 'name', 'region']),
                'staff' => $staff, 'drill_types' => EmergencyDrillService::DRILL_TYPES];
        });
    }

    private function decision(User $viewer, callable $operation): bool
    {
        try {
            return (bool) $this->withCurrentViewer($viewer, $operation);
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return false;
            }
            throw $exception;
        }
    }
}
