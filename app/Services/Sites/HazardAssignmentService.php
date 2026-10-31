<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Notifications\Sites\HazardAssignedNotification;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/** One assignment command for the hazard workspace and All Tasks. */
final class HazardAssignmentService
{
    private const PERMISSIONS = ['hazards.view', 'hazards.assign', 'sites.viewAny', 'sites.viewAll', 'sites.type.house.view', 'sites.type.facility.view', 'sites.type.head_office.view'];

    public function __construct(
        private readonly UserSiteAccessService $sites,
        private readonly AuthorizationEvidenceLockService $authorization,
    ) {}

    /** @return array{owner_changed:bool, due_changed:bool, assignee_name:string, actor:User} */
    public function assign(User $actor, int $hazardId, ?int $assigneeId, array $options = [], bool $notify = true, bool $requireRead = false): array
    {
        $data = Validator::make(['assigned_to_user_id' => $assigneeId, ...$options], [
            'assigned_to_user_id' => 'required|integer|min:1',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'expected_assigned_to_user_id' => 'sometimes|nullable|integer',
            'expected_due_date' => 'sometimes|nullable|date_format:Y-m-d',
        ])->validate();
        try {
            return DB::transaction(function () use ($actor, $hazardId, $assigneeId, $data, $notify, $requireRead): array {
                $hazard = SiteHazard::query()->whereKey($hazardId)->lockForUpdate()->firstOrFail();

                return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $hazard, $assigneeId, $data, $notify, $requireRead): array {
                    $currentActor = $this->authorization->lockForUserWithoutWaiting($actor, self::PERMISSIONS);
                    $site = $reads->query(Site::query())->find($hazard->site_id);
                    abort_unless($site && in_array((int) $site->id, $this->sites->accessibleSiteIds($currentActor, ['sites.viewAll'], $reads), true), 404);
                    abort_unless(in_array($site->type, app(SiteTypeAccessService::class)->allowedTypes($currentActor), true), 404);
                    Gate::forUser($currentActor)->authorize('view', $site);
                    abort_unless($currentActor->canDo('hazards.assign'), 403);
                    abort_unless(! $requireRead || $currentActor->canDo('hazards.view'), 403);
                    if ($hazard->status === 'closed') {
                        throw ValidationException::withMessages(['assigned_to_user_id' => 'This hazard is closed. Its recorded details cannot be changed.']);
                    }
                    $candidate = $this->eligibleStaff($currentActor, $assigneeId, $reads);
                    if (! $candidate) {
                        throw ValidationException::withMessages(['assigned_to_user_id' => 'Choose current staff from your approved sites.']);
                    }
                    $previousOwnerId = $hazard->assigned_to_user_id;
                    $previousDueDate = $hazard->due_date?->toDateString();
                    if ((array_key_exists('expected_assigned_to_user_id', $data)
                            && ($data['expected_assigned_to_user_id'] === null ? null : (int) $data['expected_assigned_to_user_id']) !== $previousOwnerId)
                        || (array_key_exists('expected_due_date', $data) && $data['expected_due_date'] !== $previousDueDate)) {
                        throw ValidationException::withMessages(['assigned_to_user_id' => 'The owner or due date has changed. Reload this hazard before assigning it again.']);
                    }
                    $dueDate = array_key_exists('due_date', $data) ? $data['due_date'] : $previousDueDate;
                    $ownerChanged = $previousOwnerId !== $assigneeId;
                    $dueChanged = $previousDueDate !== $dueDate;
                    if ($ownerChanged || $dueChanged) {
                        $previousAssignedAt = $hazard->assigned_at?->toISOString();
                        $hazard->forceFill([
                            'assigned_to_user_id' => $assigneeId, 'due_date' => $dueDate,
                            'assigned_at' => $ownerChanged ? now() : $hazard->assigned_at,
                        ])->saveQuietly();
                        if ($ownerChanged) {
                            AuditLogger::logOrFail('hazard.assigned', $hazard, [
                                'actor_id' => $currentActor->id, 'previous_assignee_id' => $previousOwnerId,
                                'assignee_id' => $candidate->id, 'assignee_name' => $candidate->name,
                                'previous_due_date' => $previousDueDate, 'due_date' => $dueDate,
                                'previous_assigned_at' => $previousAssignedAt, 'assignment_source' => 'manual',
                            ]);
                        }
                        if ($dueChanged) {
                            AuditLogger::logOrFail('hazard.due_date_changed', $hazard, ['actor_id' => $currentActor->id, 'from' => $previousDueDate, 'to' => $dueDate]);
                        }
                    }
                    if ($ownerChanged && $notify) {
                        $snapshot = clone $hazard;
                        $snapshot->setRelation('site', $site);
                        DB::afterCommit(function () use ($snapshot): void {
                            try {
                                User::find($snapshot->assigned_to_user_id)?->notify(new HazardAssignedNotification($snapshot));
                            } catch (Throwable $error) {
                                Log::warning('Hazard assignment notification failed after commit', ['hazard_id' => $snapshot->id, 'error_class' => $error::class]);
                            }
                        });
                    }

                    return ['owner_changed' => $ownerChanged, 'due_changed' => $dueChanged, 'assignee_name' => $candidate->name, 'actor' => $currentActor];
                });
            }, attempts: 3);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['assigned_to_user_id' => 'The hazard or its access is changing. Reload it before assigning again.']);
            }
            throw $error;
        }
    }

    /** Reuse current-employment rules while fencing every approved-Site and HR read. */
    public function eligibleStaff(User $actor, int $staffId, CurrentAuthorizationReads $reads): ?User
    {
        $reads->assertActive();
        $siteIds = $this->sites->accessibleSiteIds($actor, ['sites.viewAll'], $reads);
        $query = $this->sites->applyStaffScope(User::query()->whereKey($staffId), $actor, ['sites.viewAll']);
        if (! $this->sites->canBypass($actor, ['sites.viewAll'])) {
            // The ordinary read scope may contain a pre-transaction cache or
            // MySQL snapshot. It never expands this explicit current Site set.
            $query->whereHas('hrEmployeeProfile', function ($profile) use ($siteIds): void {
                $profile->where(function ($scope) use ($siteIds): void {
                    $scope->whereIn('primary_site_id', $siteIds);
                    foreach ($siteIds as $siteId) {
                        $scope->orWhereJsonContains('secondary_site_ids', $siteId);
                    }
                });
            });
        }

        return $reads->query($query)->first();
    }
}
