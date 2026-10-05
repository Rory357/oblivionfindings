<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Current entry authority after reservation, balance and optional cover waits. */
final class HrLeaveEntryCommandAccessService
{
    public const SELF = 'self';

    public const MANAGED = 'managed';

    public const ROSTER = 'roster';

    public const ADJUSTMENT = 'adjustment';

    public function __construct(
        private readonly HrCurrentStaffService $currentStaff,
        private readonly UserSiteAccessService $siteAccess,
        private readonly AuthorizationEvidenceLockService $authorization,
    ) {}

    /** @return array{0:User,1:User} Current actor and current scoped subject/profile. */
    public function lock(User $actor, User|int $subject, string $entry): array
    {
        if (! in_array($entry, [self::SELF, self::MANAGED, self::ROSTER, self::ADJUSTMENT], true)) {
            throw new InvalidArgumentException('Unknown leave command entry.');
        }
        $subjectId = $subject instanceof User ? (int) $subject->id : $subject;

        try {
            $actor = $this->authorization->lockForUserWithoutWaiting($actor, [
                'hr.leave.viewAny', 'hr.leave.manage',
                'staff.availability.updateAny', 'staff.availability.updateSelf',
            ]);
            abort_unless($actor->isApproved(), 403);
            $isSelf = (int) $actor->id === $subjectId;

            if ($entry === self::SELF) {
                abort_unless($isSelf, 403);
            } elseif ($entry === self::ROSTER) {
                abort_unless($actor->canDo('staff.availability.updateAny')
                    || ($isSelf && $actor->canDo('staff.availability.updateSelf')), 403);
            } else {
                // An explicit management entry remains management even when
                // the subject is the actor. It cannot fall back to self-service.
                abort_unless($actor->canDo('hr.leave.viewAny') && $actor->canDo('hr.leave.manage'), 403);
            }

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $subjectId, $isSelf): array {
                $query = $this->currentStaff->currentUsersQuery()->whereKey($subjectId);
                if (! $isSelf) {
                    $siteIds = $this->siteAccess->accessibleSiteIds($actor, [], $reads);
                    $query->whereHas('hrEmployeeProfile', function (Builder $profile) use ($siteIds): void {
                        $profile->where(function (Builder $sites) use ($siteIds): void {
                            $sites->whereIn('primary_site_id', $siteIds);
                            foreach ($siteIds as $siteId) {
                                $sites->orWhereJsonContains('secondary_site_ids', $siteId);
                            }
                        });
                    });
                }

                $subject = $reads->query($query)->firstOrFail(['users.id', 'users.role', 'users.approved_at']);
                $profile = $reads->query(HrEmployeeProfile::query())
                    ->where('user_id', $subjectId)
                    ->firstOrFail(['id', 'user_id', 'hours_per_week', 'is_active', 'start_date', 'end_date', 'primary_site_id', 'secondary_site_ids']);
                $subject->setRelation('hrEmployeeProfile', $profile);

                return [$actor, $subject];
            });
        } catch (QueryException $exception) {
            self::rethrowContention($exception);
        }
    }

    public static function rethrowContention(QueryException $exception): never
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'leave_request' => 'Leave access is being updated. Please try again.',
        ]);
    }
}
